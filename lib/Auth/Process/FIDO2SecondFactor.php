<?php

namespace SimpleSAML\Module\fido2SecondFactor\Auth\Process;

/**
 * FIDO2/WebAuthn Authentication Processing filter
 *
 * Filter for registering or authenticating with a FIDO2/WebAuthn token after
 * having authenticated with the primary authsource.
 *
 * @author Stefan Winter <stefan.winter@restena.lu>
 * @package SimpleSAMLphp
 */
use SimpleSAML\Logger;
use SimpleSAML\Module;
use SimpleSAML\Utils;

class FIDO2SecondFactor extends \SimpleSAML\Auth\ProcessingFilter {

    /**
     * backend storage configuration. Required.
     *
     * @var \SimpleSAML\Module\fido2SecondFactor\Store
     */
    private $store;

    /**
     * Scope of the FIDO2 attestation. Can only be in the own domain.
     *
     * @var string
     */
    private $scope;

    /**
     * attribute to use as username for the FIDO2 attestation.
     *
     * @var string
     */
    private $usernameAttrib;

    /**
     * attribute to use as display name for the FIDO2 attestation.
     *
     * @var string
     */
    private $displaynameAttrib;

    /**
     * Initialize filter.
     *
     * Validates and parses the configuration.
     *
     * @param array $config Configuration information.
     * @param mixed $reserved For future use.
     *
     * @throws \SimpleSAML\Error\Exception if the configuration is not valid.
     */
    public function __construct($config, $reserved) {
        assert(is_array($config));
        parent::__construct($config, $reserved);


        try {
            $this->store = \SimpleSAML\Module\fido2SecondFactor\Store::parseStoreConfig($config['store']);
        } catch (\Exception $e) {
            Logger::error(
                    'fido2SecondFactor: Could not create storage: ' .
                    $e->getMessage()
            );
        }

        if (array_key_exists('scope', $config)) {
            $this->scope = $config['scope'];
        } else {
            $this->scope = "NEEDTODERIVE";
        }

        if (array_key_exists('attrib_username', $config)) {
            $this->usernameAttrib = $config['attrib_username'];
        } else {
            Logger::error('fido2SecondFactor: it is required to set attrib_username in config.');
        }

        if (array_key_exists('attrib_displayname', $config)) {
            $this->displaynameAttrib = $config['attrib_displayname'];
        } else {
            Logger::error('fido2SecondFactor: it is required to set attrib_displayname in config.');
        }
    }

    /**
     * Process a authentication response
     *
     * This function saves the state, and redirects the user to the page where 
     * the user can register or authenticate with his token.
     *
     * @param array &$state The state of the response.
     *
     * @return void
     */
    public function process(&$state) {
        assert(is_array($state));
        assert(array_key_exists('UserID', $state));
        assert(array_key_exists('Destination', $state));
        assert(array_key_exists('entityid', $state['Destination']));
        assert(array_key_exists('metadata-set', $state['Destination']));
        assert(array_key_exists('entityid', $state['Source']));
        assert(array_key_exists('metadata-set', $state['Source']));

        $idpEntityId = $state['Source']['entityid'];
        if ($this->scope == "NEEDTODERIVE") {
            $protoHostname = substr($idpEntityId, 0, strpos($idpEntityId, '/', 8));
            $hostname = substr($protoHostname, strrpos($protoHostname, '/') + 1);
            $this->scope = $hostname;
        }

        $state['fido2SecondFactor:store'] = $this->store;
        Logger::debug('fido2SecondFactor: userid: ' . $state['Attributes'][$this->usernameAttrib][0]);

        if ($this->store->is2FAEnabled($state['Attributes'][$this->usernameAttrib][0]) === false) {
            // nothing to be done here, end authprocfilter processing
            return;
        }

        $state['FIDO2Tokens'] = $this->store->getTokenData($state['Attributes'][$this->usernameAttrib][0]);
        $state['FIDO2Scope'] = $this->scope;
        $state['FIDO2Username'] = $state['Attributes'][$this->usernameAttrib][0];
        $state['FIDO2Displayname'] = $state['Attributes'][$this->displaynameAttrib][0];
        $state['FIDO2SignupChallenge'] = hash('sha512', random_bytes(64));

        // Save state and redirect
        $id = \SimpleSAML\Auth\State::saveState($state, 'fido2SecondFactor:request');
        $url = Module::getModuleURL('fido2SecondFactor/fido2.php');
        Utils\HTTP::redirectTrustedURL($url, ['StateId' => $id]);
    }

}
