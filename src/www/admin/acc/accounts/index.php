<?php
namespace Garradin;

use Garradin\Accounting\Charts;
use Garradin\Accounting\Accounts;
use Garradin\Entities\Accounting\Account;

require_once __DIR__ . '/../_inc.php';

$chart = $year->chart();
$accounts = $chart->accounts();

$tpl->assign('chart', $chart);
$tpl->assign('accounts_grouped', $accounts->listCommonGrouped());

$tpl->display('acc/accounts/index.tpl');
