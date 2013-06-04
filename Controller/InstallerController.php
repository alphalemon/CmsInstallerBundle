<?php
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

namespace AlphaLemon\CmsInstallerBundle\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Mysqli\MysqliConnection;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;

use AlphaLemon\CmsInstallerBundle\Core\Form\AlphaLemonCmsParametersType;
use AlphaLemon\CmsInstallerBundle\Core\Installer\Installer;

/**
 * Implements the controller to install AlphaLemon CMS
 *
 * @author alphalemon <webmaster@alphalemon.com>
 */
class InstallerController extends Controller
{
    public function installAction()
    {
        $type   = new AlphaLemonCmsParametersType();
        $domain = 'website.' . exec('hostname');
        $form   = $this->container->get('form.factory')->create($type, array('domain'   => $domain,
                                                                             'company'  => 'Acme',
                                                                             'bundle'   => 'WebSiteBundle',
                                                                             'host'     => 'localhost',
                                                                             'database' => 'alphalemon',
                                                                             'user'     => 'root',
                                                                             'driver'   => 'mysql',
                                                                             'port'     => '3306',
        ));

        $request = $this->container->get('request');
        $scheme  = $request->getScheme() . '://' . $request->getHttpHost();
        if ('POST' === $request->getMethod()) {
            $form->bindRequest($request);
            if ($form->isValid()) {
                $data = $form->getData();
                try {
                    $dbCon     = dbConnection::getInstanceByArray($data);
                    $response  = $this->render('AlphaLemonCmsInstallerBundle:Installer:install_success.html.twig', array(
                        'scheme' => $scheme,
                    ));
                    $installer = $this->container->get('installer.service.alphalemon');
                    $installer->install($data['company'], $data['bundle'], $dbCon, $data['generate'],$data['domain']);
                    $this->get('kernel')->registerBundles()
                    return $response;
                } catch (\Exception $ex) {
                    $this->get('session')->setFlash('error', $ex->getMessage());
                }
            }
        }
        return $this->render('AlphaLemonCmsInstallerBundle:Installer:install.html.twig', array(
            'form' => $form->createView(),
        ));
    }
}

/**
 * Class dbConnection
 * @package AlphaLemon\CmsInstallerBundle\Controller
 */
class dbConnection
{
    /**
     * @var string
     */
    public $name;
    /**
     * @var string
     */
    public $driver;
    /**
     * @var string
     */
    public $host;
    /**
     * @var string
     */
    public $port;
    /**
     * @var string
     */
    public $database;

    /**
     * @var array
     */
    protected $allowedDrivers = array('mysql', 'pgsql');

    /**
     * @param string $user
     * @param string $password
     * @param string $database
     * @param string $driver
     * @param string $host
     * @param int $port
     */
    public function __construct($user, $password, $database, $driver = 'mysql', $host = 'localhost', $port = 3306)
    {
        if (in_array($driver, $this->allowedDrivers)) {
            $this->user     = $user;
            $this->password = $password;
            $this->driver   = $driver;
            $this->database = $database;
            $this->host     = $host;
            $this->port     = $port;
        } else {
            new \Exception('driver not allowed');
        }
    }

    /**
     * @return string get the complete connection dns with database name
     */
    public function getDsn()
    {
        return sprintf('%s;dbname=%s', $this->getShortDsn(), $this->database);
    }

    /**
     * @return string delivers the short dns without database name
     */
    public function getShortDsn()
    {
        switch ($this->driver) {
            case 'pgsql':
                $dsn = sprintf('%s:host=%s;port=%s;user=%s;password=%s', $this->driver, $this->host, $this->port, $this->user, $this->password);
                break;
            case 'mysql':
            default:
                $dsn = sprintf('%s:host=%s;port=%s', $this->driver, $this->host, $this->port);
                break;
        }
        return $dsn;
    }

    /**
     * @param $data
     * @return dbConnection
     */
    public static function getInstanceByArray($data)
    {
        return new self($data['user'], $data['password'], $data['database'], $data['driver'], $data['host'], $data['port']);
    }
}

