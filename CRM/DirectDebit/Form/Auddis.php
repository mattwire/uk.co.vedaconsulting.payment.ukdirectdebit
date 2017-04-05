<?php

require_once 'CRM/Core/Form.php';
require_once 'CRM/Core/Session.php';
require_once 'CRM/Core/PseudoConstant.php';

class CRM_DirectDebit_Form_Auddis extends CRM_Core_Form {

  // Form Path: civicrm/directdebit/auddis
  // This form
  /*
 * Notification of failed debits and cancelled or amended DDIs are made available via Automated Direct Debit
 * Instruction Service (AUDDIS), Automated Return of Unpaid Direct Debit (ARUDD) files and Automated Direct Debit
 * Amendment and Cancellation (ADDACS) files. Notification of any claims relating to disputed Debits are made via
 * Direct Debit Indemnity Claim Advice (DDICA) reports.
 */

  function buildQuickForm() {
    $auddisFiles = array();
    $aruddFiles = array();
    $auddisIDs = explode(',', CRM_Utils_Request::retrieve('auddisID', 'String', $this, false));
    $aruddIDs = explode(',', CRM_Utils_Request::retrieve('aruddID', 'String', $this, false));

    if (!empty($auddisIDs) || !empty($aruddIDs)) {
      if (!empty($auddisIDs)) {
        foreach ($auddisIDs as $auddisID) {
          $auddisFiles[] = CRM_DirectDebit_Auddis::getSmartDebitAuddisFile($auddisID);
        }
      }
      if (!empty($aruddIDs)) {
        foreach ($aruddIDs as $aruddID) {
          $aruddFiles[] = CRM_DirectDebit_Auddis::getSmartDebitAruddFile($aruddID);
        }
      }

      // Display the rejected payments
      $newAuddisArray = array();
      $key = 0;
      $rejectedIds = array();
      foreach ($auddisFiles as $auddisFile) {
        unset($auddisFile['auddis_date']);
        foreach ($auddisFile as $inside => $value) {
          $sql = "
          SELECT ctrc.id as contribution_recur_id ,ctrc.contact_id , cont.display_name ,ctrc.start_date , ctrc.amount, ctrc.trxn_id , ctrc.frequency_unit, ctrc.frequency_interval
          FROM civicrm_contribution_recur ctrc
          LEFT JOIN civicrm_contact cont ON (ctrc.contact_id = cont.id)
          WHERE ctrc.trxn_id = %1";

          $params = array(1 => array($value['reference'], 'String'));
          $dao = CRM_Core_DAO::executeQuery($sql, $params);
          $rejectedIds[] = "'" . $value['reference'] . "' ";
          if ($dao->fetch()) {
            $newAuddisArray[$key]['contribution_recur_id'] = $dao->contribution_recur_id;
            $newAuddisArray[$key]['contact_id'] = $dao->contact_id;
            $newAuddisArray[$key]['contact_name'] = $dao->display_name;
            $newAuddisArray[$key]['start_date'] = $dao->start_date;
            $newAuddisArray[$key]['frequency'] = $dao->frequency_interval.' '.$dao->frequency_unit;
            $newAuddisArray[$key]['amount'] = $dao->amount;
            $newAuddisArray[$key]['transaction_id'] = $dao->trxn_id;
            $newAuddisArray[$key]['reference'] = $value['reference'];
            $newAuddisArray[$key]['reason-code'] = $value['reason-code'];
            $key++;
          }
        }
      }

      // Calculate the total rejected
      $totalRejected = 0;
      foreach ($newAuddisArray as $key => $value) {
        $totalRejected += $value['amount'];
      }
      $summary['Rejected Contribution in the auddis']['count'] = count($newAuddisArray);
      $summary['Rejected Contribution in the auddis']['total'] = CRM_Utils_Money::format($totalRejected);
      $this->assign('totalRejected', $totalRejected);

      $newAruddArray = array();
      $key = 0;
      foreach ($aruddFiles as $aruddFile) {
        unset($aruddFile['arudd_date']);
        foreach ($aruddFile as $inside => $value) {
          $sql = "
          SELECT ctrc.id contribution_recur_id ,ctrc.contact_id , cont.display_name ,ctrc.start_date , ctrc.amount, ctrc.trxn_id , ctrc.frequency_unit, ctrc.frequency_interval
          FROM civicrm_contribution_recur ctrc
          LEFT JOIN civicrm_contact cont ON (ctrc.contact_id = cont.id)
          WHERE ctrc.trxn_id = %1";

          $params = array(1 => array($value['ref'], 'String'));
          $dao = CRM_Core_DAO::executeQuery($sql, $params);
          $rejectedIds[] = "'" . $value['ref'] . "' ";
          if ($dao->fetch()) {
            $newAruddArray[$key]['contribution_recur_id'] = $dao->contribution_recur_id;
            $newAruddArray[$key]['contact_id'] = $dao->contact_id;
            $newAruddArray[$key]['contact_name'] = $dao->display_name;
            $newAruddArray[$key]['start_date'] = $dao->start_date;
            $newAruddArray[$key]['frequency'] = $dao->frequency_interval.' '.$dao->frequency_unit;
            $newAruddArray[$key]['amount'] = $dao->amount;
            $newAruddArray[$key]['transaction_id'] =  $dao->trxn_id;
            $newAruddArray[$key]['reference'] = $value['ref'];
            $newAruddArray[$key]['reason-code'] = $value['returnDescription'];
            $key++;
          }
        }
      }

      // Calculate the total rejected
      $totalRejectedArudd = 0;
      foreach ($newAruddArray as $key => $value) {
        $totalRejectedArudd += $value['amount'];
      }
      $summary['Rejected Contribution in the arudd']['count'] = count($newAruddArray);
      $summary['Rejected Contribution in the arudd']['total'] = CRM_Utils_Money::format($totalRejectedArudd);
      $this->assign('totalRejectedArudd', $totalRejectedArudd);
      $listArray = array();

      // Display the valid payments
      $transactionIdList = "'dummyId'";
      $contributiontrxnId = "'dummyId'";
      $sdTrxnIds = array();
      $selectQuery = "SELECT `transaction_id` as trxn_id, receive_date as receive_date FROM `veda_civicrm_smartdebit_import`";
      $dao = CRM_Core_DAO::executeQuery($selectQuery);
      while ($dao->fetch()) {
        $transactionIdList .= ", '" . $dao->trxn_id . "' "; // Transaction ID
        $sdTrxnIds[] = "'" . $dao->trxn_id . "' ";
        $contributiontrxnId .= ", '" . $dao->trxn_id . '/' . CRM_Utils_Date::processDate($dao->receive_date) . "' ";
      }

      $contributionQuery = "
        SELECT cc.contact_id, cc.total_amount, cc.trxn_id as cc_trxn_id, ctrc.trxn_id as ctrc_trxn_id
        FROM `civicrm_contribution` cc
        INNER JOIN civicrm_contribution_recur ctrc ON (ctrc.id = cc.contribution_recur_id)
        WHERE cc.`trxn_id` IN ( $contributiontrxnId )";

      $dao = CRM_Core_DAO::executeQuery($contributionQuery);
      $contriTraIds = "'dummyId'";
      $processedIds = "'dummyId'";
      $proIds = array();
      $matchTrxnIds = array();
      $missingArray = array();
      while ($dao->fetch()) {
        $processedIds .= ", '" . $dao->ctrc_trxn_id . "' ";
        $proIds[] = "'" . trim($dao->ctrc_trxn_id) . "' "; //MV: trim the whitespaces and match the transaction_id.
        $contriTraIds .= ", '" . $dao->cc_trxn_id . "' ";
      }
      $validIds = array_diff($sdTrxnIds, $proIds, $rejectedIds);

      if (!empty($validIds)) {
        $validIdsString = implode(',', $validIds);
        $sql = "SELECT ctrc.id contribution_recur_id ,ctrc.contact_id , cont.display_name ,ctrc.start_date , sdpayments.amount, ctrc.trxn_id , ctrc.frequency_unit, ctrc.payment_instrument_id, ctrc.contribution_status_id, ctrc.frequency_interval
      FROM civicrm_contribution_recur ctrc
      INNER JOIN veda_civicrm_smartdebit_import sdpayments ON sdpayments.transaction_id = ctrc.trxn_id
      INNER JOIN civicrm_contact cont ON (ctrc.contact_id = cont.id)
      WHERE ctrc.trxn_id IN ($validIdsString)";

        $dao = CRM_Core_DAO::executeQuery($sql);
        $key = 0;
        while ($dao->fetch()) {
          $matchTrxnIds[] = "'" . trim($dao->trxn_id) . "' ";
          $params = array('contribution_recur_id' => $dao->contribution_recur_id,
            'contact_id' => $dao->contact_id,
            'contact_name' => $dao->display_name,
            'start_date' => $dao->start_date,
            'frequency' => $dao->frequency_interval . ' ' . $dao->frequency_unit,
            'amount' => $dao->amount,
            'contribution_status_id' => $dao->contribution_status_id,
            'transaction_id' => $dao->trxn_id,
          );

          // Allow params to be validated via hook
          CRM_DirectDebit_Utils_Hook::validateSmartDebitContributionParams($params);

          $listArray[$key] = $params;
          $key++;
        }
        //MV: temp store the matched contribution in settings table.
        if (!empty($matchTrxnIds)) {
          uk_direct_debit_civicrm_saveSetting('result_ids', $matchTrxnIds);
        }
      }

      // Show the already processed contributions
      $contributionQuery = "
        SELECT cc.contact_id, cont.display_name, cc.total_amount, cc.trxn_id, ctrc.start_date, ctrc.frequency_unit, ctrc.frequency_interval
        FROM `civicrm_contribution` cc
        LEFT JOIN civicrm_contribution_recur ctrc ON (ctrc.id = cc.contribution_recur_id)
        INNER JOIN civicrm_contact cont ON (cc.contact_id = cont.id)
        WHERE cc.`trxn_id` IN ( $contributiontrxnId )";
      $dao = CRM_Core_DAO::executeQuery($contributionQuery);
      $existArray = array();
      $key = 0;
      while ($dao->fetch()) {
        $existArray[$key]['contact_id'] = $dao->contact_id;
        $existArray[$key]['contact_name'] = $dao->display_name;
        $existArray[$key]['start_date'] = $dao->start_date;
        $existArray[$key]['frequency'] = $dao->frequency_interval . ' ' . $dao->frequency_unit;
        $existArray[$key]['amount'] = $dao->total_amount;
        $existArray[$key]['transaction_id'] = $dao->trxn_id;
        $key++;
      }
      $totalExist = 0;
      foreach ($existArray as $value) {
        $totalExist += $value['amount'];
      }

      $summary['Contribution already processed']['count'] = count($existArray);
      $summary['Contribution already processed']['total'] = CRM_Utils_Money::format($totalExist);

      $missingTrxnIds = array_diff($validIds, $matchTrxnIds);
      if (!empty($missingTrxnIds)) {
        $missingTrxnIdsString = implode(',', $missingTrxnIds);
        $findMissingQuery = "
          SELECT `transaction_id` as trxn_id, contact as display_name, amount as amount
          FROM `veda_civicrm_smartdebit_import`
          WHERE transaction_id IN ($missingTrxnIdsString)";
        $dao = CRM_Core_DAO::executeQuery($findMissingQuery);
        $key = 0;
        while ($dao->fetch()) {
          $missingArray[$key]['contact_name'] = $dao->display_name;
          $missingArray[$key]['amount'] = $dao->amount;
          $missingArray[$key]['transaction_id'] = $dao->trxn_id;
          $key++;
        }
      }
      $totalMissing = 0;
      foreach ($missingArray as $value) {
        $totalMissing += $value['amount'];
      }
      $summary['Contribution not matched to contacts']['count'] = count($missingArray);
      $summary['Contribution not matched to contacts']['total'] = CRM_Utils_Money::format($totalMissing);

      // Create query url for continue
      $queryParams = '';
      if (!empty($queryParams)) { $queryParams.='&'; }
      $queryParams .= 'reset=1';

      // Set valid flag
      $validResults = true;
    }
    else {
      // No AUDDIS or ARUDD dates
      CRM_Core_Session::setStatus('You haven\'t selected any AUDDIS or ARUDD dates for import! Go back and select some to continue', 'Smart Debit', 'alert');
      $validResults = false;
    }

    $bQueryParams = '';
    if (!empty($bQueryParams)) { $bQueryParams.='&'; }
    $bQueryParams.='reset=1';

    $redirectUrlBack = CRM_Utils_System::url('civicrm/directdebit/syncsd', $bQueryParams);
    $buttons[] = array(
            'type' => 'back',
            'js' => array('onclick' => "location.href='{$redirectUrlBack}'; return false;"),
            'name' => ts('Back'),
    );
    if(!empty($matchTrxnIds) && $validResults) {
      $redirectUrlContinue  = CRM_Utils_System::url('civicrm/directdebit/syncsd/confirm', $queryParams);
      $buttons[] = array(
            'type' => 'next',
            'js' => array('onclick' => "location.href='{$redirectUrlContinue}'; return false;"),
            'name' => ts('Continue'),
      );
    }
    $this->addButtons($buttons);
    CRM_Utils_System::setTitle('Synchronise CiviCRM with Smart Debit: View Results');

    if ($validResults) {
      $totalList = 0;
      foreach ($listArray as $value) {
        $totalList += $value['amount'];
      }

      $summary['Contribution matched to contacts']['count'] = count($listArray);
      $summary['Contribution matched to contacts']['total'] = CRM_Utils_Money::format($totalList);

      $totalSummaryNumber = count($newAuddisArray) + count($newAruddArray) + count($existArray) + count($missingArray) + count($listArray);
      $totalSummaryAmount = $totalRejected + $totalRejectedArudd + $totalExist + $totalMissing + $totalList;

      $this->assign('newAuddisArray', $newAuddisArray);
      $this->assign('newAruddArray', $newAruddArray);
      $this->assign('listArray', $listArray);
      $this->assign('total', CRM_Utils_Money::format($totalList));
      $this->assign('totalExist', CRM_Utils_Money::format($totalExist));
      $this->assign('totalMissing', CRM_Utils_Money::format($totalMissing));
      $this->assign('existArray', $existArray);
      $this->assign('missingArray', $missingArray);
      $this->assign('summaryNumber', $totalSummaryNumber);
      $this->assign('totalSummaryAmount', CRM_Utils_Money::format($totalSummaryAmount));
      $this->assign('summary', $summary);
    }

    parent::buildQuickForm();
  }
}
