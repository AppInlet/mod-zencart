<?php

class PayfastConfig {
    private $server;
    private $passphrase;
    private $debugEmail;
    private $moduleInfo;

    public function __construct() {
        $this->server = (strcasecmp(MODULE_PAYMENT_PF_SERVER, 'live') == 0) ?
            MODULE_PAYMENT_PF_SERVER_LIVE : MODULE_PAYMENT_PF_SERVER_TEST;
        $this->passphrase = MODULE_PAYMENT_PF_PASSPHRASE;
        $this->debugEmail = defined('MODULE_PAYMENT_PF_DEBUG_EMAIL_ADDRESS') ?
            MODULE_PAYMENT_PF_DEBUG_EMAIL_ADDRESS : STORE_OWNER_EMAIL_ADDRESS;
        $this->moduleInfo = [
            'pfSoftwareName' => PF_SOFTWARE_NAME,
            'pfSoftwareVer' => PF_SOFTWARE_VER,
            'pfSoftwareModuleName' => PF_MODULE_NAME,
            'pfModuleVer' => PF_MODULE_VER,
        ];
    }

    public function getServer() {
        return $this->server;
    }

    public function getPassphrase() {
        return $this->passphrase;
    }

    public function getDebugEmail() {
        return $this->debugEmail;
    }

    public function getModuleInfo() {
        return $this->moduleInfo;
    }
}
