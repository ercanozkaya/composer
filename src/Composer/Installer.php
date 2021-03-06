<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer;

use Composer\Autoload\AutoloadGenerator;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\Downloader\DownloadManager;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\Link;
use Composer\Package\LinkConstraint\VersionConstraint;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Repository\ArrayRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\EventDispatcher;
use Composer\Script\ScriptEvents;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Beau Simensen <beau@dflydev.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class Installer
{
    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var PackageInterface
     */
    protected $package;

    /**
     * @var DownloadManager
     */
    protected $downloadManager;

    /**
     * @var RepositoryManager
     */
    protected $repositoryManager;

    /**
     * @var Locker
     */
    protected $locker;

    /**
     * @var InstallationManager
     */
    protected $installationManager;

    /**
     * @var EventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @var AutoloadGenerator
     */
    protected $autoloadGenerator;

    protected $preferSource = false;
    protected $devMode = false;
    protected $dryRun = false;
    protected $verbose = false;
    protected $update = false;

    /**
     * @var array
     */
    protected $suggestedPackages;

    /**
     * @var RepositoryInterface
     */
    protected $additionalInstalledRepository;

    /**
     * Constructor
     *
     * @param IOInterface $io
     * @param PackageInterface $package
     * @param DownloadManager $downloadManager
     * @param RepositoryManager $repositoryManager
     * @param Locker $locker
     * @param InstallationManager $installationManager
     * @param EventDispatcher $eventDispatcher
     * @param AutoloadGenerator $autoloadGenerator
     */
    public function __construct(IOInterface $io, PackageInterface $package, DownloadManager $downloadManager, RepositoryManager $repositoryManager, Locker $locker, InstallationManager $installationManager, EventDispatcher $eventDispatcher, AutoloadGenerator $autoloadGenerator)
    {
        $this->io = $io;
        $this->package = $package;
        $this->downloadManager = $downloadManager;
        $this->repositoryManager = $repositoryManager;
        $this->locker = $locker;
        $this->installationManager = $installationManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->autoloadGenerator = $autoloadGenerator;
    }

    /**
     * Run installation (or update)
     */
    public function run()
    {
        if ($this->dryRun) {
            $this->verbose = true;
        }

        if ($this->preferSource) {
            $this->downloadManager->setPreferSource(true);
        }

        // create installed repo, this contains all local packages + platform packages (php & extensions)
        $repos = array_merge(
            $this->repositoryManager->getLocalRepositories(),
            array(
                new InstalledArrayRepository(array($this->package)),
                new PlatformRepository(),
            )
        );
        $installedRepo = new CompositeRepository($repos);
        if ($this->additionalInstalledRepository) {
            $installedRepo->addRepository($this->additionalInstalledRepository);
        }

        $aliases = $this->aliasPackages();

        if (!$this->dryRun) {
            // dispatch pre event
            $eventName = $this->update ? ScriptEvents::PRE_UPDATE_CMD : ScriptEvents::PRE_INSTALL_CMD;
            $this->eventDispatcher->dispatchCommandEvent($eventName);
        }

        $this->suggestedPackages = array();
        if (!$this->doInstall($this->repositoryManager->getLocalRepository(), $installedRepo, $aliases)) {
            return false;
        }
        if ($this->devMode) {
            if (!$this->doInstall($this->repositoryManager->getLocalDevRepository(), $installedRepo, $aliases, true)) {
                return false;
            }
        }

        // output suggestions
        foreach ($this->suggestedPackages as $suggestion) {
            if (!$installedRepo->findPackages($suggestion['target'])) {
                $this->io->write($suggestion['source'].' suggests installing '.$suggestion['target'].' ('.$suggestion['reason'].')');
            }
        }

        if (!$this->dryRun) {
            // write lock
            if ($this->update || !$this->locker->isLocked()) {
                $updatedLock = $this->locker->setLockData(
                    $this->repositoryManager->getLocalRepository()->getPackages(),
                    $this->devMode ? $this->repositoryManager->getLocalDevRepository()->getPackages() : null,
                    $aliases,
                    $this->package->getMinimumStability(),
                    $this->package->getStabilityFlags()
                );
                if ($updatedLock) {
                    $this->io->write('<info>Writing lock file</info>');
                }
            }

            // write autoloader
            $this->io->write('<info>Generating autoload files</info>');
            $localRepos = new CompositeRepository($this->repositoryManager->getLocalRepositories());
            $this->autoloadGenerator->dump($localRepos, $this->package, $this->installationManager, $this->installationManager->getVendorPath() . '/composer', true);

            // dispatch post event
            $eventName = $this->update ? ScriptEvents::POST_UPDATE_CMD : ScriptEvents::POST_INSTALL_CMD;
            $this->eventDispatcher->dispatchCommandEvent($eventName);
        }

        return true;
    }

    protected function doInstall($localRepo, $installedRepo, $aliases, $devMode = false)
    {
        $minimumStability = $this->package->getMinimumStability();
        $stabilityFlags = $this->package->getStabilityFlags();

        // initialize locker to create aliased packages
        if (!$this->update && $this->locker->isLocked($devMode)) {
            $lockedPackages = $this->locker->getLockedPackages($devMode);
            $minimumStability = $this->locker->getMinimumStability();
            $stabilityFlags = $this->locker->getStabilityFlags();
        }

        // creating repository pool
        $pool = new Pool($minimumStability, $stabilityFlags);
        $pool->addRepository($installedRepo);
        foreach ($this->repositoryManager->getRepositories() as $repository) {
            $pool->addRepository($repository);
        }

        // creating requirements request
        $installFromLock = false;
        $request = new Request($pool);

        $constraint = new VersionConstraint('=', $this->package->getVersion());
        $request->install($this->package->getName(), $constraint);

        if ($this->update) {
            $this->io->write('<info>Updating '.($devMode ? 'dev ': '').'dependencies</info>');

            $request->updateAll();

            $links = $devMode ? $this->package->getDevRequires() : $this->package->getRequires();

            foreach ($links as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }
        } elseif ($this->locker->isLocked($devMode)) {
            $installFromLock = true;
            $this->io->write('<info>Installing '.($devMode ? 'dev ': '').'dependencies from lock file</info>');

            if (!$this->locker->isFresh() && !$devMode) {
                $this->io->write('<warning>Your lock file is out of sync with your composer.json, run "composer.phar update" to update dependencies</warning>');
            }

            foreach ($lockedPackages as $package) {
                $version = $package->getVersion();
                foreach ($aliases as $alias) {
                    if ($alias['package'] === $package->getName() && $alias['version'] === $package->getVersion()) {
                        $version = $alias['alias_normalized'];
                        break;
                    }
                }
                $constraint = new VersionConstraint('=', $version);
                $request->install($package->getName(), $constraint);
            }
        } else {
            $this->io->write('<info>Installing '.($devMode ? 'dev ': '').'dependencies</info>');

            $links = $devMode ? $this->package->getDevRequires() : $this->package->getRequires();

            foreach ($links as $link) {
                $request->install($link->getTarget(), $link->getConstraint());
            }
        }

        // fix the version of all installed packages (+ platform) that are not
        // in the current local repo to prevent rogue updates (e.g. non-dev
        // updating when in dev)
        foreach ($installedRepo->getPackages() as $package) {
            if ($package->getRepository() === $localRepo) {
                continue;
            }

            $constraint = new VersionConstraint('=', $package->getVersion());
            $request->install($package->getName(), $constraint);
        }

        // prepare solver
        $policy = new DefaultPolicy();
        $solver = new Solver($policy, $pool, $installedRepo);

        // solve dependencies
        try {
            $operations = $solver->solve($request);
        } catch (SolverProblemsException $e) {
            $this->io->write('<error>Your requirements could not be solved to an installable set of packages.</error>');
            $this->io->write($e->getMessage());

            return false;
        }

        // force dev packages to be updated if we update or install from a (potentially new) lock
        if ($this->update || $installFromLock) {
            foreach ($localRepo->getPackages() as $package) {
                // skip non-dev packages
                if (!$package->isDev()) {
                    continue;
                }

                // skip packages that will be updated/uninstalled
                foreach ($operations as $operation) {
                    if (('update' === $operation->getJobType() && $operation->getInitialPackage()->equals($package))
                        || ('uninstall' === $operation->getJobType() && $operation->getPackage()->equals($package))
                    ) {
                        continue 2;
                    }
                }

                // force update to latest on update
                if ($this->update) {
                    $newPackage = $this->repositoryManager->findPackage($package->getName(), $package->getVersion());
                    if ($newPackage && $newPackage->getSourceReference() !== $package->getSourceReference()) {
                        $operations[] = new UpdateOperation($package, $newPackage);
                    }
                } elseif ($installFromLock) {
                    // force update to locked version if it does not match the installed version
                    $lockData = $this->locker->getLockData();
                    unset($lockedReference);
                    foreach ($lockData['packages'] as $lockedPackage) {
                        if (!empty($lockedPackage['source-reference']) && strtolower($lockedPackage['package']) === $package->getName()) {
                            $lockedReference = $lockedPackage['source-reference'];
                            break;
                        }
                    }
                    if (isset($lockedReference) && $lockedReference !== $package->getSourceReference()) {
                        // changing the source ref to update to will be handled in the operations loop below
                        $operations[] = new UpdateOperation($package, $package);
                    }
                }
            }
        }

        // execute operations
        if (!$operations) {
            $this->io->write('Nothing to install or update');
        }

        foreach ($operations as $operation) {
            if ($this->verbose) {
                $this->io->write((string) $operation);
            }

            // collect suggestions
            if ('install' === $operation->getJobType()) {
                foreach ($operation->getPackage()->getSuggests() as $target => $reason) {
                    $this->suggestedPackages[] = array(
                        'source' => $operation->getPackage()->getPrettyName(),
                        'target' => $target,
                        'reason' => $reason,
                    );
                }
            }

            if (!$this->dryRun) {
                $event = 'Composer\Script\ScriptEvents::PRE_PACKAGE_'.strtoupper($operation->getJobType());
                if (defined($event)) {
                    $this->eventDispatcher->dispatchPackageEvent(constant($event), $operation);
                }

                // if installing from lock, restore dev packages' references to their locked state
                if ($installFromLock) {
                    $package = null;
                    if ('update' === $operation->getJobType()) {
                        $package = $operation->getTargetPackage();
                    } elseif ('install' === $operation->getJobType()) {
                        $package = $operation->getPackage();
                    }
                    if ($package && $package->isDev()) {
                        $lockData = $this->locker->getLockData();
                        foreach ($lockData['packages'] as $lockedPackage) {
                            if (!empty($lockedPackage['source-reference']) && strtolower($lockedPackage['package']) === $package->getName()) {
                                $package->setSourceReference($lockedPackage['source-reference']);
                                break;
                            }
                        }
                    }
                }
                $this->installationManager->execute($localRepo, $operation);

                $event = 'Composer\Script\ScriptEvents::POST_PACKAGE_'.strtoupper($operation->getJobType());
                if (defined($event)) {
                    $this->eventDispatcher->dispatchPackageEvent(constant($event), $operation);
                }

                $localRepo->write();
            }
        }

        return true;
    }

    private function aliasPackages()
    {
        if (!$this->update && $this->locker->isLocked()) {
            $aliases = $this->locker->getAliases();
        } else {
            $aliases = $this->package->getAliases();
        }

        foreach ($aliases as $alias) {
            foreach ($this->repositoryManager->findPackages($alias['package'], $alias['version']) as $package) {
                $package->setAlias($alias['alias_normalized']);
                $package->setPrettyAlias($alias['alias']);
                $package->getRepository()->addPackage($aliasPackage = new AliasPackage($package, $alias['alias_normalized'], $alias['alias']));
                $aliasPackage->setRootPackageAlias(true);
            }
            foreach ($this->repositoryManager->getLocalRepositories() as $repo) {
                foreach ($repo->findPackages($alias['package'], $alias['version']) as $package) {
                    $package->setAlias($alias['alias_normalized']);
                    $package->setPrettyAlias($alias['alias']);
                    $package->getRepository()->addPackage($aliasPackage = new AliasPackage($package, $alias['alias_normalized'], $alias['alias']));
                    $aliasPackage->setRootPackageAlias(true);
                }
            }
        }

        return $aliases;
    }

    /**
     * Create Installer
     *
     * @param IOInterface $io
     * @param Composer $composer
     * @param EventDispatcher $eventDispatcher
     * @param AutoloadGenerator $autoloadGenerator
     * @return Installer
     */
    static public function create(IOInterface $io, Composer $composer, EventDispatcher $eventDispatcher = null, AutoloadGenerator $autoloadGenerator = null)
    {
        $eventDispatcher = $eventDispatcher ?: new EventDispatcher($composer, $io);
        $autoloadGenerator = $autoloadGenerator ?: new AutoloadGenerator;

        return new static(
            $io,
            $composer->getPackage(),
            $composer->getDownloadManager(),
            $composer->getRepositoryManager(),
            $composer->getLocker(),
            $composer->getInstallationManager(),
            $eventDispatcher,
            $autoloadGenerator
        );
    }

    public function setAdditionalInstalledRepository(RepositoryInterface $additionalInstalledRepository)
    {
        $this->additionalInstalledRepository = $additionalInstalledRepository;

        return $this;
    }

    /**
     * wether to run in drymode or not
     *
     * @param boolean $dryRun
     * @return Installer
     */
    public function setDryRun($dryRun = true)
    {
        $this->dryRun = (boolean) $dryRun;

        return $this;
    }

    /**
     * prefer source installation
     *
     * @param boolean $preferSource
     * @return Installer
     */
    public function setPreferSource($preferSource = true)
    {
        $this->preferSource = (boolean) $preferSource;

        return $this;
    }

    /**
     * update packages
     *
     * @param boolean $update
     * @return Installer
     */
    public function setUpdate($update = true)
    {
        $this->update = (boolean) $update;

        return $this;
    }

    /**
     * enables dev packages
     *
     * @param boolean $update
     * @return Installer
     */
    public function setDevMode($devMode = true)
    {
        $this->devMode = (boolean) $devMode;

        return $this;
    }

    /**
     * run in verbose mode
     *
     * @param boolean $verbose
     * @return Installer
     */
    public function setVerbose($verbose = true)
    {
        $this->verbose = (boolean) $verbose;

        return $this;
    }
}
