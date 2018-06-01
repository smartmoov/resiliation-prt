<?php
/**
 * Created by PhpStorm.
 * User: stephanepau
 * Date: 28/05/2017
 * Time: 11:32
 * PHP Version 7.1
 *
 * @category PrtServices
 * @package  Resiliation
 * @author   Stephane <smartmoov.solutions@gmail.com>
 * @license  https://smartmoov.solutions/license smartmoov
 * @link     https://resilier.online
 */
namespace prt\WebServices;

use Illuminate\Support\Facades\Log;

/**
 * Class PrtServices
 *
 * @category PrtServices
 * @package  App\WebServices
 * @author   Stephane <smartmoov.solutions@gmail.com>
 * @license  https://smartmoov.solutions/license smartmoov
 * @link     https://resilier.online
 *
 */
abstract class PrtServices
{
    /**
     * PRT Service description
     */
    protected const WSDL_FILE = 'http://hcetest.prtnet.eu/ws40/nexus.asmx?WSDL';

    /**
     * Service namespace
     */
    protected const NAMESPACE = 'http://services.hce.prtgroup.eu/nexus/';

    /**
     *  SoapHeader AuthHeader
     *
     * @var array
     */
    protected $authHeader = array();

    /**
     * Client Soap pour les appels PRT
     *
     * @var \SoapClient|\PrtSoap
     */
    protected $soapClient;

    /**
     * PrtSpooling constructor.
     */
    public function __construct()
    {
        $this->authHeader = array(
            'Username' => getenv('PRT_USERNAME_TEST'),
            'Password' => getenv('PRT_PASSWORD_TEST')
        );
        $this->soapClient = new \SoapClient(self::WSDL_FILE, array('uri' => self::NAMESPACE, 'trace' => 1));

        $header = new \SoapHeader(self::NAMESPACE, 'AuthHeader', $this->authHeader);
        $this->soapClient->__setSoapHeaders($header);
    }

    /**
     * Test whether PRT server is online or not
     *
     * @return bool
     */
    public function isAlive(): bool
    {
        try {
            $this->soapClient->IsAlive();
        } catch (\SoapFault $e) {
            Log::error($e->getMessage());
            return false;
        } catch (\Exception $e) {
            Log::critical('PRT Server offline' . $e->getMessage());
            return false; //Service offline
        }
        return true;
    }
}