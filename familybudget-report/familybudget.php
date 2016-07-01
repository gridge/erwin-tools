<?php 
//==========
//Expects input parameters posted:
// startDate (starting date to consider, format: 'YYYY-MM-DD')
// endDate (ending date to consider. format: 'YYYY-MM-DD')
// reportType (defines what type of accounts end up in the resulting array)
//	      - 'ListAccounts'  -> Simple list of all account names and types
//        - 'ProfitLoss'    -> INCOME-EXPENSES
//        - 'ProfitLossNoBT'-> INCOME-EXPENSES but excludes business-travel related
//        - 'ExpensesNoBT'  -> EXPENSES but excludes business-travel related
//	      - 'BusinessTrips' -> INCOME-EXPENSES business-travel related
//        - 'HistoryMonth'  -> Return account for fixed monthly time itervales from start- to end-date
//                             'Balance' represents overall Balance
//                             'Equity' represents sum of Assets+Cash-Liabilities
// account (if provided, only look at the given account and sub-accounts)
// mainCurrency (define main currency to use, e.g. 'USD' or 'EUR')
// sumBalanceToParent to sum up sub-account balances to parent account
// suppressZeroBalance to suppress from the output accounts with zero balance
//
//
//Return an JSON formatted array of arrays of the form:
//[
// ['Account': 'name',
//  'Type': 'EXPENSE',
//  'Balance': 1234.00,
//  'Indent': 1, 
//  'Date': '2016/06/31',
// ],
//]
//to be used to initialize a data table, where
// Account -> name of account (string)
// Type    -> type of account (EXPENSE, INCOME, ASSET, CASH, LIABILITY, EQUITY, ...)
// Balance -> balance of account (float)
// Indent  -> indentation to identify sub-accounts in the ordered list of accounts (integer, 0=account requested)
// Date    -> Date the balance refers to (end date)
//
// IMPORTANT NOTES:
//  - does not support multiple accounts with the same name (reports error)
//==========

//=== General settings
// List of accounts defined as business-travel related
$businessTripsAccounts = [
  'Travel LBL reimbursable',
  'Reimbursements LBL',
  'Travel LBL',
];

// Sum balances for sub-accounts below max indentation
$maxIndent=3;

// Reverse sign for the following account types
$reverseSign = [
  'INCOME',
  'EXPENSE'
];

// Debugging option 
// 0 = disabled, 
// 1 = enabled, echo
// 2 = enabled, console
$debugging=0; 
// Locale
setlocale(LC_MONETARY, 'en_US');

//=== Input parameters with defaults
// Set period of time covered
if ( isset($_POST['startDate']) ) {
  $tmpDate = strtotime($_POST['startDate']); //normal string format  
  $startDate=date('Ymd', $tmpDate) . '000000';
} else {
  //default: current month (01 to last day of the month)
  $startDate=date('Ym').'01'.'000000';
}
if ( isset($_POST['endDate']) ) {
  $tmpDate = strtotime($_POST['endDate']); //normal string format      
  $endDate=date('Ymd', $tmpDate) . '000000';
} else {
  //default: current month (01 to last day of the month)
  $endDate=date('Ymt').'000000';
}
// Set what information to retrieve
if ( isset($_POST['reportType']) ) {
  $reportType=$_POST['reportType'];
} else {
  //mostly set for debugging
  $reportType='ProfitLossNoBT';
  $reportType='HistoryMonth'; //SPG
}
// Set account name
if ( isset($_POST['account']) ) {
  $account = $_POST['account'];
} else {
  $account = 'Root Account';    
}
// Set main currency
if ( isset($_POST['mainCurrency']) ) {
  $mainCurrency=$_POST['mainCurrency'];
} else {
  $mainCurrency='USD';
}
// Sum sub-account balances to parent account (also through POST)
if ( isset($_POST['sumBalanceToParent']) ) {
  $sumBalanceToParent=(bool)$_POST['sumBalanceToParent'];
}  else {
  $sumBalanceToParent=False;
}
// Suppress zero-balance accounts (also through POST)
if ( isset($_POST['suppressZeroBalance']) ) {
  $suppressZeroBalance=(bool)$_POST['suppressZeroBalance'];  
}  else {
  $suppressZeroBalance=True;
}



//=== Results
//Resulting array
$finalListAccounts=array();

//error handling
$returnFirstError='';
$returnLastError='';

//=== Debugging utility
//http://php.net/manual/en/debugger.php
function console_log( $data )
{
  global $debugging;

  if ($debugging == 0) return;
  if ($debugging == 1) {
    var_dump($data);
    echo '<p>';
  } elseif ($debugging == 2) {
    echo '<script>';
    echo 'console.log('. json_encode( $data ) .')';
    echo '</script>';
  }
}

function report_error( $error ) 
{
  global $returnLastError, $returnFirstError;

  console_log($error);
  //only return first and last error
  if ($returnFirstError == '') {
    $returnFirstError = $error;
  }  
  $returnLastError = $error;
}

//=== Utility functions
//Get number of rows of query result
//http://php.net/manual/en/class.sqlite3result.php
function getNRows($result) 
{
   $nrows=0;
   $result->reset();
   while ($result->fetchArray())
     $nrows++;
   $result->reset();
   return $nrows;
}

//Not really used now on the PHP side
function formatBalance($balance, $currency)
{
  $s = '';
  switch ($currency) {
  case 'USD':
    $s = $s . '$';
    break;
  case 'EUR':
    $s = $s . 'E';
    break;
  default:
    $s = $s . '?';    
  }
  $s = $s . money_format('%(!.2i', $balance);  
  return $s;
}

//Format date from internal format to YYY/MM/DD
function formatDate($wDate) 
{
  $year = substr($wDate, 0, 4);
  $month = substr($wDate, 4, 2);
  $day = substr($wDate, 6, 2);
  return $year . '/' . $month . '/' . $day;
}

//Return balance of the given account
function getGncBalance($accountName, $startDate, $endDate) 
{
  global $db;
  global $mainCurrency;

  console_log('Get balance of account \'' . $accountName . '\'');
  // - if placeholder, return 0
  $q='Select hidden, placeholder';
  $q= $q . ' From accounts Where accounts.name = \'' . $accountName . '\'';
  console_log($q);
  $result = $db->query($q);
  $nrows = getNRows($result);
  if ($nrows == 0) {
    //account not found
    report_error('Did not find account: '. $accountName);
    return 0.0;
  } elseif ($nrows > 1) {
    report_error('Multiple accounts with the same name detected, not supported: '.$accountName);
    return 0.0;
  }
  $accountRecord = $result->fetchArray();
  if (($accountRecord['hidden'] != 0) || ($accountRecord['placeholder'] != 0)) {
    //no balance for place-holder or hidden accounts
    console_log('Account is hidden or placeholder. Return 0.0');
    return 0.0;
  }

  // - get cummodity for this account
  $q = 'Select commodities.mnemonic From accounts,commodities';
  $q = $q . ' Where accounts.name = \''.$accountName.'\'';
  $q = $q . ' And accounts.commodity_guid=commodities.guid';
  console_log($q);
  $result = $db->query($q);
  $nrows = getNRows($result);
  if ($nrows == 0) {
    //check if a commodity guid is set at all
    $q = 'Select commodity_guid from accounts Where name = \''.$accountName.'\'';
    $result= $db->query($q);
    $guidCommodity = $result->fetchArray()["commodity_guid"];
    if ($guidCommodity == null) {
      // no commodity defined for this account, ok (likely a 'Root Account'
      console_log('No commodity defined for account: '.$accountName.'. OK. Return 0.0;');
      return 0.0;
    } else {
      report_error('Did not find commodity guid for account: '.$accountName.'; commodity_guid='.$guidCommodity);
      return 0.0;      
    }
  }  
  $accountRecord = $result->fetchArray();
  $accountCommodity=$accountRecord['mnemonic'];
  console_log('Commodity = ' . $accountCommodity);

  // - get exchange rate, if not main currency
  $convRate=1.0;
  if ($accountCommodity != $mainCurrency) {
    $q = 'Select value_num,value_denom From prices';
    $q = $q . ' Where commodity_guid =';
    $q = $q . ' (Select guid From commodities Where mnemonic=\''.$accountCommodity.'\')';
    $q = $q . ' And currency_guid = ';
    $q = $q . ' (Select guid From commodities Where mnemonic=\'' . $mainCurrency . '\')';
    $q = $q . ' Order By date DESC';
    console_log($q);
    $result = $db->query($q);
    $nrows = getNRows($result);
    if ($nrows == 0) {
       report_error('Could not convert commodity '.$accountCommodity.' for account '.$accountCommodity);
       return 0.0;
    }  
    $accountRecord = $result->fetchArray();
    $convRate = (real)$accountRecord['value_num'];
    $convRate = $convRate / $accountRecord['value_denom'];
  }
  console_log('Conversion rate (' . $accountCommodity . '/' . $mainCurrency . '): ' . $convRate);

  // - calculate balance and convert in main currency
  $q = 'Select Sum(Cast(splits.value_num As Real) / splits.value_denom)';
  $q = $q . ' From splits Inner Join accounts On accounts.guid = splits.account_guid Inner Join';
  $q = $q . ' transactions On splits.tx_guid = transactions.guid';
  $q = $q . ' Where accounts.name = \''.$accountName.'\'';
  $q = $q . ' And transactions.post_date >= \''.$startDate.'\'';
  $q = $q . ' And transactions.post_date <= \''.$endDate.'\'';
  console_log($q);
  $result = $db->query($q);
  $accountRecord = $result->fetchArray();
  $balance = (real)$accountRecord[0];
  console_log('Balance in original commodity: ' . $balance);
  $balance = $balance * $convRate;

  // - return final balance
  console_log('Balance (' . $mainCurrency . '): ' . $balance);
  return $balance;
  
};

//Get account type
function getGncAccountType($accountName)
{
  global $db;

  $q = 'Select account_type From accounts Where';
  $q = $q . ' name = \'' . $accountName . '\'';
  $result = $db->query($q);
  $accountRecord = $result->fetchArray();
  $type = $accountRecord[0];
  console_log('Account \'' . $accountName . '\', type: \'' . $type . '\'');
  return $type;
}

//Return $listAccounts array (same format as final result, see above)
//starting from $accountName and going recursively into sub-accounts;
//Exclude accounts specified in $excludeList
//If $includeList is not-null only consider those accounts
//Filter type of account if $accountTypes is non-null list.
function getGncRecursiveAccountBalance($accountName, $startDate, $endDate, $accountTypes, $excludeList, &$listAccounts, $indent = 0)
{
  global $db;
  global $mainCurrency;
  global $sumBalanceToParent, $suppressZeroBalance;
  global $maxIndent, $reverseSign;

  console_log('Get recursive balance for account \'' . $accountName . '\'');
  console_log(' allowed types = ' . print_r($accountTypes,True));
  console_log(' excludeList = ' . print_r($excludeList,True));
  console_log(' indent = ' . $indent);
  //console_log(' current array = ' . $listAccounts );
  
  // - check exclude list
  if (in_array($accountName, $excludeList)) {
    console_log('Account in exclude list.');
    return 0.0;
  }

  // - Get type of account and check type list
  $type = getGncAccountType($accountName);
  if (! (in_array($type, $accountTypes) or (count($accountTypes) == 0)) ) {     
    // do not consider, return previous list
    console_log('Not of valid type. Return.');
    return 0.0;
  }

  //- Get balance of requested account
  $balance = getGncBalance($accountName, $startDate, $endDate);
  if (in_array($type, $reverseSign)) {
    $balance = -$balance;
  }
  
  // - Add account to list of accounts with appropriate :indent (if :indent < :maxIndent)
  if ($indent <= $maxIndent) {
    $listAccounts[] = array(
      'Account' => $accountName,
      'Type' => $type,
//      'Balance' => array('v' => $balance, 'f' => formatBalance($balance, $mainCurrency)),
      'Balance' => $balance,
      'Indent' => $indent,
      'Date' => formatDate($endDate),
    );  
  };
  //keep track of the element we just added for updating it
  end($listAccounts);
  $lastAccount_key = key($listAccounts);
  // - Get list of sub-accounts 
  //    Type and excludeList will be checked by this recursive function itself
  $q = 'Select accounts.name From accounts';
  $q = $q . ' Where parent_guid =';
  $q = $q . ' (Select accounts.guid From accounts';
  $q = $q . ' Where accounts.name = \'' . $accountName . '\')';
  console_log($q);
  $listSubs = $db->query($q);

  // - For each sub-account call getGncRecursiveAccountBalance function recursively with :indent+1
  $sumBalanceSubs = 0.0;
  $sumAbsBalanceSubs = 0.0;
  while ($subAccount = $listSubs->fetchArray()) {
      $subBal = getGncRecursiveAccountBalance($subAccount['name'], $startDate, $endDate, $accountTypes, $excludeList, $listAccounts, $indent+1);
      $sumBalanceSubs = $sumBalanceSubs + $subBal;
      $sumAbsBalanceSubs = $sumAbsBalanceSubs + abs($subBal);
  }

  // - Update list of accounts with final balance for this account (if requested or if :indent >= :maxIndent)
  //   Also update balance to be returned with total anyway
  $balance = $balance + $sumBalanceSubs;
  console_log('Recursive balance for ' . $accountName . ': ' . $listAccounts[$lastAccount_key]['Balance'] . '(incl. sub-accounts: ' . $balance .')');
  if (($indent == $maxIndent) or $sumBalanceToParent) {
    //in this case we also update the record 
    $listAccounts[$lastAccount_key]['Balance'] = $balance;
//    $listAccounts[$lastAccount_key]['Balance']['v'] = $balance;
//    $listAccounts[$lastAccount_key]['Balance']['f'] = formatBalance($balance, $mainCurrency);
  }

  // - If balance is zero, check if we need/can suppress it
  if ( (abs($balance) < 0.00001) And ($suppressZeroBalance) And ($indent <= $maxIndent) And 
       ((getNRows($listSubs) == 0) or ($sumAbsBalanceSubs < 0.00001)) ) {
    console_log('Suppressing zero-balance ' . $accountName);
    unset($listAccounts[$lastAccount_key]);
  }
  
  // - Return balance of this account (with sub-accounts)
  return $balance;
};


// Exclude specific accounts from output
function removeAccounts(&$listAccounts, $excludeList)
{
  //exclude each account and sub-accounts
  $excludeAllFollowing=$maxIndent; //to keep track of sub-accounts to exclude
  foreach ($listAccounts as $key => $value) {  
      if ($value['Indent'] > $excludeAllFollowing) {
        unset($listAccounts[$key]);
        continue;
      }
      if (in_array($key, $excludeList)) {
        $excludeAllFollowing = $value['Indent'];        
        unset($listAccounts[$key]);
      }
  }
}


//=== Main routine
//Open connection to gnucash DB
try {
//  console_log('Opening DB: ' . 'FamilyBudget.gnucash');
  console_log('Opening DB: ' . 'FamilyBudget.gnucash');
  $db = new SQLite3('FamilyBudget.gnucash');
} catch (Exception $exception) {
    report_error('Error attaching to SQLite3 database: '.$exception);
    echo $returnLastError;
    return;
}

switch ($reportType) {
case 'ListAccounts':
  //Simple list of all account names and types (and balances)
  getGncRecursiveAccountBalance($account, $startDate, $endDate, [], [], $finalListAccounts);
  if ($finalListAccounts[0]['Account'] == 'Root Account') {
    unset($finalListAccounts[0]); //delete the 'Root Account' entry
  };
  break;
case 'ProfitLoss':
  //INCOME-EXPENSES
  $bal = getGncRecursiveAccountBalance($account, $startDate, $endDate, ['ROOT', 'INCOME', 'EXPENSE'], [], $finalListAccounts);
  //rename Root Account
  if ($finalListAccounts[0]['Account'] == 'Root Account') {
    $finalListAccounts[0]['Account'] = 'Profit/Loss';
    $finalListAccounts[0]['Balance'] = $bal; //always use grand-total
//    $finalListAccounts[0]['Balance']['v'] = $bal; //always use grand-total
//    $finalListAccounts[0]['Balance']['f'] = formatBalance($bal,$mainCurrency); //always use grand-total
  };
  break;
case 'ProfitLossNoBT':
  //INCOME-EXPENSES but excludes business-travel related
  $bal = getGncRecursiveAccountBalance($account, $startDate, $endDate, ['ROOT', 'INCOME', 'EXPENSE'], $businessTripsAccounts, $finalListAccounts);
  //rename Root Account
  if ($finalListAccounts[0]['Account'] == 'Root Account') {
    $finalListAccounts[0]['Account'] = 'Profit/Loss';
    $finalListAccounts[0]['Balance'] = $bal; //always use grand-total
  };
  break;
case 'ExpensesNoBT':
  //EXPENSES but excludes business-travel related
  unset($reverseSign); //do not reverse sign for expense accounts
  $reverseSign = array();
  $bal = getGncRecursiveAccountBalance($account, $startDate, $endDate, ['ROOT', 'EXPENSE'], $businessTripsAccounts, $finalListAccounts);
  //remove Root Account
  if ($finalListAccounts[0]['Account'] == 'Root Account') {
      unset($finalListAccounts[0]);
  };
  break;
case 'BusinessTrips':
  //INCOME-EXPENSES business-travel related
  $bal=0.0;
  //special: reverse sign also for ASSET (they're reimbursable expenses)
  $reverseSign[] = 'ASSET';
  foreach ($businessTripsAccounts as $includeAccount) {
      $bal = $bal + getGncRecursiveAccountBalance($includeAccount, $startDate, $endDate, [], [], $finalListAccounts, 2);
  }
  //Add Total
  $totalToAdd = array('Account' => 'TOTAL Business Travel',
                      'Type' => 'ROOT',
                      'Balance' => $bal,
                      'Indent' => 0,
                      'Date' => formatDate($endDate));
  array_unshift($finalListAccounts, $totalToAdd);
  break;
case 'HistoryMonth':
    console_log('HistoryMonth report');
  //get month by month balance from start to end date
    $currentYear = (integer)substr($startDate, 0, 4);
    $currentMonth = (integer)substr($startDate, 4, 2);
    $currentDay = ((integer)substr($startDate, 6, 2)) - 1; //subtract 1 since we're adding it in the loop
    $targetYear = (integer)substr($endDate, 0, 4);
    $targetMonth = (integer)substr($endDate, 4, 2);
    $targetDay = (integer)substr($endDate, 6, 2);
    $reachedEndDate=false;
    if (($currentYear > $targetYear) or 
    (($currentYear == $targetYear) and ($currentMonth > $targetMonth)) or
    (($currentYear == $targetYear) and ($currentMonth == $targetMonth) and ($currentDay > $targetDay))) {
        //invalid range
        $reachedEndDate=true;
    } 
    while ($reachedEndDate == false) {
        $currentDay = $currentDay + 1; //start from +1 day from previous iteration (removed 1 for first iteration above)
        $startTS = mktime(0, 0, 0, $currentMonth, $currentDay, $currentYear);
        $currentYear = (integer)date("Y", $startTS);
        $currentMonth = (integer)date("m", $startTS);
        $currentDay = (integer)date("d", $startTS);        
        console_log('y='.$currentYear.', m='.$currentMonth.', d='.$currentDay);
        $tmpStartDate = sprintf('%04d%02d%02d', $currentYear, $currentMonth, $currentDay) . '000000';

        $endTS = mktime(0, 0, 0, $currentMonth+1, $currentDay-1, $currentYear);
        $currentYear = (integer)date("Y", $endTS);
        $currentMonth = (integer)date("m", $endTS);
        $currentDay = (integer)date("d", $endTS);
        if (($currentYear == $targetYear) and ($currentMonth == $targetMonth)) {
          if ($currentDay > $targetDay) {
            $currentDay = $targetDay; //stop at target day at most, even if shorter month
          }
        }
        $tmpEndDate = sprintf('%04d%02d%02d', $currentYear, $currentMonth, $currentDay) . '000000';

        if ($tmpEndDate >= $endDate) {
          $tmpEndDate = $endDate;
          $reachedEndDate = true;
        }
        console_log('y='.$currentYear.', m='.$currentMonth.', d='.$currentDay);

        //query the following information:
        // - balance of each account up to $maxIndent (type: 'ROOT', 'INCOME', 'EXPENSE')
        // - overall balance (rename 'Root Account' -> 'Balance')
        // - equity (asset+cash-liability), only end-date set
        $typeOfAccounts=[]; //default: all if a single account is requested
        if ($account == 'Root Account') {
            $typeOfAccounts=['ROOT', 'INCOME', 'EXPENSE'];
        }
        //qery
        console_log('Querying for dates: ' . $tmpStartDate . ' -- ' . $tmpEndDate);
        $bal = getGncRecursiveAccountBalance($account, $tmpStartDate, $tmpEndDate, $typeOfAccounts, [], $finalListAccounts);
        //rename 'Root Account'
        console_log('Renaming Root -> Balance');
        //ugly enough, I need to loop over all entries to do that
        $rootAccount_key=-1;
        foreach ($finalListAccounts as $key => $value) {
          if ($value['Account'] == 'Root Account') {
            $rootAccount_key = $key;
            break;
          }
        }
        if ($rootAccount_key >= 0) {
            $finalListAccounts[$rootAccount_key]['Account'] = 'Balance';
            $finalListAccounts[$rootAccount_key]['Balance'] = $bal;
        }
        console_log(print_r($finalListAccounts,True));
        //now query for equity (set $indent to $maxIndent to only get one result)
        console_log('Get equity');
        $account = 'Root Account';
        $typeOfAccounts=['ROOT', 'ASSET', 'CASH', 'LIABILITY'];
        $tmpStartDate='19700101000000'; //need to count them all!
        getGncRecursiveAccountBalance($account, $tmpStartDate, $tmpEndDate, $typeOfAccounts, [], $finalListAccounts, $maxIndent);
        console_log('Renaming Root Account to Equity');
        //ugly enough, I need to loop over all entries to do that        
        $rootAccount_key = -1;
        foreach ($finalListAccounts as $key => $value) {
          if ($value['Account'] == 'Root Account') {
            $rootAccount_key = $key;
            break;
          }
        }
        if ($rootAccount_key >= 0) {
            $finalListAccounts[$rootAccount_key]['Account'] = 'Equity';
            $finalListAccounts[$rootAccount_key]['Indent'] = 0; //reset indent to zero
        }
        console_log(print_r($finalListAccounts,True));
    } //end loop over dates
    break;
default:
  report_error('Undefined report type: ' . $reportType);
};

//Close connection
console_log('Closing DB connection');
$db->close();

console_log('All Done');

//=== Return results back to the script, encoding it for Google Charts
$finalListAccounts = array_values($finalListAccounts);
//echo json_encode($finalListAccounts);
//format final array
$formattedArray = array();
//$formattedArray[] = array(array('label' => 'Account', 'type' => 'string'), 
//                          array('label' => 'Type', 'type' => 'string'),
//                          array('label' =>  'Balance', 'type' => 'number'),
//                          array('label' => 'Indent', 'type' => 'number'));
//$formattedArray[] = array('Account', 'Type', 'Balance', 'Indent');
//$formattedArray = array();
foreach ($finalListAccounts as $row) {
  $rowToAdd = array();
  foreach ($row as $key => $field) {
//Moved all formatting to JS
//    if ($key == 'Account') {
//      //add indentation (I'd prefer to do it at JS level actually)
//      if ($row['Indent'] > 1) {
//        $newName = str_repeat('-', $row['Indent']-1) . $field;
//      } else {
//        $newName = '<b>' . $field . '</b>';
//      }
//      $rowToAdd[] = $newName;
//    } else {
      $rowToAdd[] = $field;
//    }
  }
  $formattedArray[] = $rowToAdd;
}
if ($returnLastError != '') {
  //error occurred return it!
  console_log('');
  console_log('Errors, if any: ');
  console_log($returnFirstError);
  console_log($returnLastError);
  echo $returnLastError;
} else {
  //all OK
  echo json_encode($formattedArray);
  console.log('No errors found.');
}

?>
