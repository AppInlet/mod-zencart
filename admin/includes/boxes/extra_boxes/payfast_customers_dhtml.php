<?php

/**
 * Copyright (c) 2024 Payfast (Pty) Ltd
 * You (being anyone who is not Payfast (Pty) Ltd) may download and use this plugin / code in your own website
 * in conjunction with a registered and active Payfast account. If your Payfast account is terminated for any reason,
 * you may not use this plugin / code or part thereof.
 * Except as expressly indicated in this licence, you may not use, copy, modify or distribute this plugin / code or
 * part thereof in any way.
 */

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}


if (MODULE_PAYMENT_PF_STATUS == 'True') {
    $za_contents[] = array(
        'text' => 'Payfast Orders',
        'link' => zen_href_link('payfast.php', '', 'NONSSL')
    );
}
