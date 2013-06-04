<?php
namespace AlphaLemon\CmsInstallerBundle\Service;
/*
 * This file is part of the AlphaLemonCMS InstallerBundle and it is distributed
 * under the GPL LICENSE Version 2.0. To use this application you must leave
 * intact this copyright notice.
 *
 * Copyright (c) AlphaLemon <webmaster@alphalemon.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * For extra documentation and help please visit http://www.alphalemon.com
 *
 * @license    GPL LICENSE Version 2.0
 *
 */

use AlphaLemon\CmsInstallerBundle\Controller\dbConnection;
use Sensio\Bundle\GeneratorBundle\Command\GenerateBundleCommand;
use Sensio\Bundle\GeneratorBundle\Command\Helper\DialogHelper;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Filesystem\Filesystem;
use AlphaLemon\AlphaLemonCmsBundle\Core\CommandsProcessor;
use AlphaLemon\AlphaLemonCmsBundle\Core\Repository\Orm\OrmInterface;
use AlphaLemon\AlphaLemonCmsBundle\Core\Repository\Propel\Base\AlPropelOrm;
use Symfony\Component\HttpKernel\Event\Kernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Parser;

/**
 * Description of installer
 *
 * @author alphalemon <webmaster@alphalemoncms.com>
 */
class InstallerService
{
    const CHECK_CLASS  = 'class';
    const CHECK_FILE   = 'file';
    const CHECK_FOLDER = 'folder';

    /**
     * @var string
     */
    protected $deployBundle;
    /**
     * @var string
     */
    protected $companyName;
    /**
     * @var string
     */
    protected $bundleName;
    /**
     * @var dbConnection
     */
    protected $dbCon;
    /**
     * @var string
     */
    protected $vendorDir;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    protected $filesystem;

    /**
     * @var null
     */
    protected $orm;
    /**
     * @var \AlphaLemon\AlphaLemonCmsBundle\Core\CommandsProcessor\AlCommandsProcessor
     */
    protected $commandsProcessor;

    /**
     * @var
     */
    protected $container;

    /**
     * @var string
     */
    protected $domain;

    /**
     * @param $rootDir
     * @param $container
     */
    public function __construct($rootDir, $container)
    {
        $this->container         = $container;
        $this->rootDir           = $this->normalizePath($rootDir);
        $this->vendorDir         = $this->normalizePath($rootDir . "vendor/");
        $this->orm               = null;
        $this->commandsProcessor = new CommandsProcessor\AlCommandsProcessor($this->normalizePath($this->rootDir . 'app'));
        $this->filesystem        = new Filesystem();
    }

    /**
     * Normalize a path as a unix path
     *
     * @param   string $path
     * @return  string
     */
    protected function normalizePath($path)
    {
        return preg_replace('/\\\/', '/', $path);
    }

    /**
     * @param string $companyName
     * @param string $bundleName
     * @param dbConnection $dbcon
     * @param bool $generate
     */
    public function install($companyName, $bundleName, dbConnection $dbcon, $generate = false, $domain = null)
    {
        try {

            $this->domain       = ($domain === null) ? strtolower($companyName) . '_' . strtolower($bundleName) : $domain;
            $this->companyName  = $companyName;
            $this->bundleName   = $bundleName;
            $this->deployBundle = $companyName . $bundleName;
            $this->setUpOrm($dbcon);
            $this->dbCon = $dbcon;

            $this->checkPrerequisites($generate);

            // until here no changes
            $this->createDb($dbcon);
            if ($generate !== false) {
                $this->generateBundleFromName($companyName, $bundleName);
            }
            $this->setUpEnvironments();
            //write the config
            $this->baseConfigFile();
            $this->cmsControllerFile();
            $this->stagingConfigFiles();
            /** write routes */
            $this->writeRoutes();
            // try to add some kernel stuff better to do this on composer install in the future
            $this->manipulateAppKernel();
            $this->setup();
        } catch (\Exception $e) {
            $this->rewindBundle($companyName, $bundleName);
            $this->rewindDatabase($dbcon->database);
            return $e;
        }
    }

    /**
     * @param string $databaseName
     * @return string
     */
    private function rewindDatabase($databaseName)
    {
        try {
            $queries = array('DROP DATABASE ' . $databaseName);

            foreach ($queries as $query) {
                if (false === $this->orm->executeQuery($query)) {
                    throw new \RuntimeException("The database " . $databaseName . " could not deleted");
                }
            }
        } catch (\Exception $ex) {
            return $ex->getMessage();
        }
    }

    /**
     * @param string $company
     * @param string $name
     * @return string
     */
    private function rewindBundle($company, $name)
    {
        try {
            // create named backup for kernel and routing in case we need an rollback
            $appKernel = $this->rootDir . 'app/AppKernel.php';
            $routing   = $this->rootDir . 'app/config/routing.yml';
            $config    = $this->rootDir . 'app/config/config.yml' .
            $suffix = '.' . $company . $name;
            $path      = $this->rootDir . 'src/' . $company . ucfirst($name);
            copy($appKernel . $suffix, $appKernel);
            copy($routing . $suffix, $routing);
            copy($config . $suffix, $config);
            //@todo del folder recoursively
            rmdir($path);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param string $company
     * @param string $bundle
     * @param null $output
     */
    protected  function generateBundleFromName($company, $bundle, $output = null)
    {
        $command   = 'generate:bundle';
        $namespace = sprintf('%s\%s', $company, $bundle);
        /** @var \Symfony\Component\HttpKernel\Kernel $kernel */
        $kernel = $this->container->get('kernel');

        $command = new GenerateBundleCommand();
        $command->setContainer($this->container);
        $command->setHelperSet(new HelperSet(array('dialog'    => new DialogHelper(),
                                                   'formatter' => new FormatterHelper())));
        $arguments = array(
            '--namespace' => $namespace,
            '--dir'       => $this->rootDir . 'src',
            '--format'    => 'annotation'
        );
        $input     = new ArrayInput($arguments);
        $input->setInteractive(false);
        if ($output === null) {
            $output = new ConsoleOutput();
        }

        // create named backup for kernel and routing in case we need an rollback
        $appKernel = $this->rootDir . 'app/AppKernel.php';
        $routing   = $this->rootDir . 'app/config/routing.yml';
        $suffix    = '.' . $company . $bundle;
        copy($appKernel, $appKernel . $suffix);
        copy($routing, $routing . $suffix);

        $command->run($input, $output);

        // add bundle prefix

        $parser = new Parser();
        $dumper = new Dumper();
        $result = $parser->parse(file_get_contents($routing));
        $ulName = $this->makeUnderscoreString($company . $bundle);
        if (array_key_exists($ulName, $result)) {
            $result[$ulName]['prefix'] = '/' . $this->domain;
            $this->persistFile($routing, $dumper->dump($result, 2));
        }
    }

    /**
     * Reverse function for makeCamelString.
     *
     * @param string $string
     * @return string
     */
    private static function makeUnderscoreString($string)
    {
        $string = preg_replace('/(?<=\\w)(?=[A-Z])/', '_$1', $string);
        $string = strtolower($string);
        return str_replace('_bundle', '', $string);
    }

    /**
     * setup propel orm
     *
     * @param dbConnection $dbCon
     * @throws \RuntimeException
     */
    protected function setUpOrm(dbConnection $dbCon)
    {
        try {
            $connection = new \PropelPDO($dbCon->getShortDsn(), $dbCon->user, $dbCon->password);
            $this->orm  = new AlPropelOrm($connection);
        } catch (\Exception $ex) {
            throw new \RuntimeException("An error occoured when trying to connect the database with the given parameters. The server returned the following error:\n\n" . $ex->getMessage());
        }
    }

    /**
     * check existence of precondition do not look for package ist a generation of the package is requested
     * @param bool $generate
     * @throws \RuntimeException
     */
    protected function checkPrerequisites($generate = false)
    {
        if (($m = $this->check($this->rootDir . 'web/js/tiny_mce', self::CHECK_FOLDER)) === true) {
            $appKernelFile = $this->rootDir . 'app/AppKernel.php';
            $contents      = file_get_contents($appKernelFile);

            if ($generate === false) {
                if (($m = $this->check($appKernelFile, self::CHECK_FILE)) === true) {
                    preg_match("/[\s|\t]+new " . $this->companyName . "\\\\" . $this->bundleName . "/s", $contents, $match);
                    if (empty ($match)) {
                        $message = "\nAlphaLemon CMS requires an existing bundle to work with. You enter as working bundle the following: $this->companyName\\$this->bundleName but, the bundle is not enable in AppKernel.php file. Please add the bundle or enable it ther run the script again.\n";
                        throw new \RuntimeException($message);
                    }
                } else {
                    throw new \RuntimeException($m);
                }
            }
        } else {
            throw new \RuntimeException($m);
        }
    }

    /**
     * single check -  function assert the existence of resources
     *
     * @param string $target
     * @param string $type
     * @param string $message
     * @return bool | string
     * @throws \RuntimeException
     */
    private function check($target, $type, $message = null)
    {
        switch ($type) {
            case self::CHECK_CLASS:
                $r       = class_exists($taget);
                $message = 'An error occoured. AlphaLemon CMS requires the ' . $message . ' library. Please install that library then run the script again.';
                break;
            case self::CHECK_FILE:
                $r = is_file($target);
                break;
            case self::CHECK_FOLDER:
                $r = is_dir($target);
                break;
        }
        if ($r !== true) {
            return (($message === null) ? 'The required ' . $type . ' ' . $target . ' has not been found' : $message);
        }
        return true;
    }

    /**
     * @deprecated rely on symfony pleez
     */
    protected function setUpEnvironments()
    {

        $alBundle = $this->vendorDir . 'alphalemon/alphalemon-cms-bundle/AlphaLemon/AlphaLemonCmsBundle/Resources/environments/frontcontrollers/';
        $this->filesystem->copy($alBundle . 'alcms.php', $this->rootDir . 'web/alcms.php', true);
        $this->filesystem->copy($alBundle . 'alcms_dev.php', $this->rootDir . 'web/alcms_dev.php', true);

        $themeBundle = $this->vendorDir . 'alphalemon/theme-engine-bundle/AlphaLemon/ThemeEngineBundle/Resources/environments/frontcontrollers/';
        $this->filesystem->copy($themeBundle . 'stage.php', $this->rootDir . 'web/stage.php', true);
        $this->filesystem->copy($themeBundle . 'stage_dev.php', $this->rootDir . 'web/stage_dev.php', true);
        $this->filesystem->mkdir($this->vendorDir . '../web/uploads/assets');

        $assetPath = $this->vendorDir . '/alphalemon/alphalemon-cms-bundle/AlphaLemon/AlphaLemonCmsBundle/Resources/public/uploads/assets/';
        $this->filesystem->mkdir($assetPath . 'media');
        $this->filesystem->mkdir($assetPath . 'js');
        $this->filesystem->mkdir($assetPath . 'css');

        $this->filesystem->mkdir($this->rootDir . 'web/uploads/assets');
        $this->filesystem->mkdir($this->rootDir . 'app/propel/sql');
    }

    /**
     * @return string
     */
    protected function writeConfigurations()
    {
        try {
            $this->baseConfigFile();
            $this->cmsControllerFile();
            $this->stagingConfigFiles();
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }


    /**
     *
     */
    private function baseConfigFile()
    {
        $dumper     = new Dumper();
        $yaml       = new Parser();
        $configFile = $this->rootDir . 'app/config/config.yml';
        $target     = 'alpha_lemon';
        $target_key = 'multi_sites';
        if (($m = $this->check($configFile, self::CHECK_FILE)) === true) {
            // get yml conten
            $config = $config_org = $yaml->parse(file_get_contents($configFile));
            $config = $this->exists($this->deployBundle, $config, $target, $target_key);
            $config = $this->exists($this->deployBundle, $config, 'assetic', 'bundles');

            if ($config !== $config_org) {
                $content = $dumper->dump($config, 2);
                $this->persistFile($configFile, $content, false, $this->companyName . $this->bundleName);
            }
        } else {
            throw new \RuntimeException($m);
        }
    }

    private function cmsControllerFile()
    {
        $configFile = $this->rootDir . 'app/config/config_alcms.yml';

        $dumper = new  Dumper();
        $parser = new Parser();

        if ($this->check($configFile, self::CHECK_FILE) !== true) {
            $data     = array('imports' =>
                              array('resource' =>
                                    array('parameters.yml',
                                        '@AlphaLemonCmsBundle/Resources/config/config_alcms.yml',
                                        '@AlphaLemonCmsBundle/Resources/config/security.yml')
                              )
            );
            $contents = $dumper->dump($this->getDatabaseConfiguration($this->dbCon, $this->deployBundle, $data), 2);
            $this->persistFile($configFile, $contents);
        } else {
            // add new database entries
            $config                                        = $parser->parse(file_get_contents($configFile));
            $config['propel']['dbal'][$this->deployBundle] = $this->getDbalEntry($this->dbCon);
            $contents                                      = $dumper->dump($config, 2);
            $this->persistFile($configFile, $contents);
        }


        $configFile = $this->rootDir . 'app/config/config_alcms_dev.yml';
        if ($this->check($configFile,self::CHECK_FILE) !== true) {
            $data     = array('imports' =>
                              array('resource' =>
                                    array('parameters.yml',
                                        'config_alcms.yml',
                                        '@AlphaLemonCmsBundle/Resources/config/config_alcms_dev.yml')
                              )
            );
            $contents = $dumper->dump($this->getDatabaseConfiguration($this->dbCon, $this->deployBundle, $data), 2);
            $this->persistFile($configFile, $contents);
        }

        $configFile = $this->rootDir . 'app/config/config_alcms_test.yml';
        if ($this->check($configFile,self::CHECK_FILE) !== true) {
            $data     = array('imports' =>
                              array('resource' =>
                                    array('parameters.yml',
                                        'config_alcms_dev.yml',
                                        '@AlphaLemonCmsBundle/Resources/config/config_alcms_test.yml')
                              )
            );
            $contents = $dumper->dump($this->getDatabaseConfiguration($this->dbCon, $this->deployBundle, $data), 2);
            $this->persistFile($configFile, $contents);
        }
    }

    protected function stagingConfigFiles()
    {
        $dumper     = new Dumper();
        $configFile = $this->rootDir . 'app/config/config_stage.yml';
        if (!is_file($configFile)) {
            $data     = array('imports'   =>
                              array('resource' => 'config.yml'),
                              'framework' =>
                              array('router' =>
                                    array('resource' => '%kernel.root_dir%/config/routing_stage.yml')
                              )
            );
            $contents = $dumper->dump($data, 2);
            $this->persistFile($configFile, $contents);
        }

        $configFile = $this->rootDir . 'app/config/config_stage_dev.yml';
        if (!is_file($configFile)) {
            $data    = array('imports'   =>
                             array('resource' => 'config_dev.yml'),
                             'framework' =>
                             array('router' =>
                                   array('resource' => '%kernel.root_dir%/config/routing_stage_dev.yml')
                             )
            );
            $content = $dumper->dump($data, 2);
            $this->persistFile($configFile, $contents);
        }
    }

    /**
     * @param string $fileName
     * @param string $contents
     * @param bool $append true
     */
    protected function persistFile($fileName, $contents, $append = false, $extension = 'bak')
    {
        $backupFile = $fileName . '.' . $extension;

        if (file_exists($fileName) && !file_exists($backupFile)) {
            $this->filesystem->copy($fileName, $backupFile);
        }
        $flags = (($append === true) ? FILE_APPEND : null);
        file_put_contents($fileName, $contents, $flags);
    }

    private function getDatabaseConfiguration(dbConnection $dbCon, $deployBundle, $merger = array())
    {
        $data = array('alpha_lemon_theme_engine' => array('deploy_bundle' => $deployBundle),
                      'propel'                   => array('path'       => '%kernel.root_dir%/../vendor/propel/propel1',
                                                          'phing_path' => '%kernel.root_dir%/../vendor/phing/phing',
                                                          'dbal'       => array($deployBundle => $this->getDbalEntry($dbCon))
                      )

        );
        return array_merge($merger, $data);
    }

    private function getDbalEntry(dbConnection $dbCon)
    {
        return array('driver'             => $dbCon->driver,
                     'user'               => $dbCon->user,
                     'password'           => $dbCon->password,
                     'dsn'                => $dbCon->getDsn(),
                     'option'             => '',
                     'attributes'         => '',
                     'default_connection' => '');
    }

    protected function writeRoutes()
    {
        $dumper     = new Dumper();
        $parser     = new Parser();
        $configFile = $this->rootDir . 'app/config/routing.yml';
        if (($m = $this->check($configFile, self::CHECK_FILE)) !== true) {
            throw new \RuntimeException($m);
        }

        $config                         = $config_org = $parser->parse(file_get_contents($configFile));
        $config                         = $this->exists('config_dev.yml', $config, 'imports', 'resource');
        $config                         = $this->exists('%kernel.root_dir%/config/routing_stage_dev.yml', $config, 'framework', 'router');
        $config                         = $this->exists('@' . $this->deployBundle . '/Resources/config/site_routing.yml', $config, $this->deployBundle, 'resource');
        if ($config !== $config_org) {
            $contents = $dumper->dump($config, 2);
            $this->persistFile($configFile, $contents);
            touch($this->rootDir . 'src/' . $this->companyName . '/' . $this->bundleName . '/Resources/config/site_routing.yml');
        }

        $configFile = $this->rootDir . 'app/config/routing_alcms.yml';
        if (!is_file($configFile)) {
            $data     = array('alcms' =>
                              array('resource' => '@AlphaLemonCmsBundle/Resources/config/routing_alcms.yml')
            );
            $contents = $dumper->dump($data, 2);
            $this->persistFile($configFile, $contents);
        }

        $configFile = $this->rootDir . 'app/config/routing_alcms_dev.yml';
        if (!is_file($configFile)) {
            $data     = array('alcms'      =>
                              array('resource' => '@AlphaLemonCmsBundle/Resources/config/routing_alcms_dev.yml'),
                              '_alcms_dev' =>
                              array('router' =>
                                    array('resource' => 'routing_alcms.yml')
                              )
            );
            $contents = $dumper->dump($data, 2);
            $this->persistFile($configFile, $contents);
        }

        $configFile = $this->rootDir . 'app/config/routing_alcms_test.yml';
        if (!is_file($configFile)) {
            $data     = array('alcms_dev'       =>
                              array('resource' => 'routing_alcms_dev.yml'),
                              '_al_text_bundle' =>
                              array('resource' => '@TextBundle/Resources/config/routing/routing.xml')
            );
            $contents = $dumper->dump($data, 2);
            $this->persistFile($configFile, $contents);
        }

        $configFile = $this->rootDir . 'app/config/routing_stage.yml';
        if (!is_file($configFile)) {
            $data     = array($this->deployBundle . 'Stage' =>
                              array('resource' => '@' . $this->deployBundle . '/Resources/config/site_routing_stage.yml')
            );
            $contents = $dumper->dump($data, 2);
            $this->persistFile($configFile, $contents);
        }

        $configFile = $this->rootDir . 'app/config/routing_stage_dev.yml';
        if (!is_file($configFile)) {
            $data     = array('_stage_prod' =>
                              array('resource' => 'routing_stage.yml'),
                              '_stage_dev'  =>
                              array('resource' => 'routing_dev.yml')
            );
            $contents = $dumper->dump($data, 2);
            $this->persistFile($configFile, $contents);
        }
    }

    /**
     * @param dbConnection $dbCon
     * @throws \Exception
     */
    protected function createDb(dbConnection $dbCon)
    {
        try {
            $queries = array('CREATE DATABASE IF NOT EXISTS ' . $dbCon->database);

            foreach ($queries as $query) {
                if (false === $this->orm->executeQuery($query)) {
                    throw new \RuntimeException("The database " . $dbCon->database . " already exists. Check your configuration parameters");
                }
            }
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    /**
     * add the master
     * @deprecated evil eval evil
     */
    protected function manipulateAppKernel()
    {
        $updateFile = false;
        $kernelFile = $this->rootDir . 'app/AppKernel.php';
        $contents   = file_get_contents($kernelFile);

        if (strpos($contents, 'new AlphaLemon\BootstrapBundle\AlphaLemonBootstrapBundle()') === false) {
            $cmsBundles = "\n            new AlphaLemon\BootstrapBundle\AlphaLemonBootstrapBundle(),\n";
            $cmsBundles .= "        );";
            $contents   = preg_replace('/[\s]+\);/s', $cmsBundles, $contents);
            $updateFile = true;
        }

        if (strpos($contents, 'new \AlphaLemon\BootstrapBundle\Core\Autoloader\BundlesAutoloader') === false) {
            $cmsBundles = "\n\n        \$bootstrapper = new \AlphaLemon\BootstrapBundle\Core\Autoloader\BundlesAutoloader(__DIR__, \$this->getEnvironment(), \$bundles);\n";
            $cmsBundles .= "        \$bundles = \$bootstrapper->getBundles();\n\n";
            $cmsBundles .= "        return \$bundles;";
            $contents   = preg_replace('/[\s]+return \$bundles;/s', $cmsBundles, $contents);
            $updateFile = true;
        }

        if (strpos($contents, 'inserbundleenvloader') === false) {
            $cmsBundles = "//inserbundleenvloader";
            $cmsBundles .= "\n        \$configFolder = __DIR__ . '/config/bundles/config/' . \$this->getEnvironment();\n";
            $cmsBundles .= "        \$finder = new \Symfony\Component\Finder\Finder();\n";
            $cmsBundles .= "        \$configFiles = \$finder->depth(0)->name('*.yml')->in(\$configFolder);\n";
            $cmsBundles .= "        foreach (\$configFiles as \$config) {\n";
            $cmsBundles .= "            \$loader->load((string)\$config);\n";
            $cmsBundles .= "        };\n\n";
            $cmsBundles .= "        \$loader->load(__DIR__.'/config/config_'.\$this->getEnvironment().'.yml');";

            $contents   = preg_replace('/[\s]+\$loader\-\>load\(__DIR__\.\'\/config\/config_\'\.\$this\-\>getEnvironment\(\).\'.yml\'\);/s', $cmsBundles, $contents);
            $updateFile = true;
        }

        if ($updateFile) $this->persistFile($kernelFile, $contents, false, 'alphalemon');

        return;
    }

    /**
     * commandline opps
     */
    protected function setup()
    {
        $symlink       = (in_array(strtolower(PHP_OS), array('unix', 'linux'))) ? ' --symlink' : '';
        $assetsInstall = 'assets:install --env=alcms_dev ' . $this->rootDir . 'web' . $symlink;
        $populate      = sprintf('alphalemon:populate --env=alcms_dev "%s" --user=%s --password=%s', $this->dbCon->getDsn(), $this->dbCon->user, $this->dbCon->password);
        $commands      = array('propel:build --insert-sql --env=alcms_dev' => null,
                               $assetsInstall                              => null,
                               $populate                                   => null,
                               'assetic:dump --env=alcms_dev'              => null,
                               'cache:clear --env=alcms_dev'               => null,
        );
        $this->commandsProcessor->executeCommands($commands, function ($type, $buffer) {
            echo $buffer;
        });
    }

    /**
     * check for existens of element in the haystack array
     *
     * @param string $item
     * @param array $haystack
     * @param string $first
     * @param string $second
     * @return bool
     */
    private function exists($item, array $haystack, $first = 'imports', $second = 'resource')
    {
        if (array_key_exists($first, $haystack) && array_key_exists($second, $haystack[$first])) {
            if (is_array($haystack[$first][$second]) && !in_array($item, $haystack[$first][$second])) {
                $haystack[$first][$second][] = $item;
            } elseif (!is_array($haystack[$first][$second]) && $haystack[$first][$second] !== $item) {
                $org                       = $haystack[$first][$second];
                $haystack[$first][$second] = array($org, $item);
            } else {
                $haystack[$first][$second] = $item;
            }
        }
        return $haystack;
    }
}
