<?php
/* this administrative page provides simple access to recent transactions
   and an opportunity for the system to warn administrators about failing
   crons */

require_once 'CRM/Core/Page.php';

class CRM_iATS_Page_iATSAdmin extends CRM_Core_Page {
  function run() {
    // the current time
    $this->assign('currentTime', date('Y-m-d H:i:s'));
    $this->assign('jobLastRunWarning', '0');
    // check if I've got any recurring contributions setup. In theory I should only worry about iATS, but it's a problem regardless ..
    $params = array('version' => 3, 'sequential' => 1);
    $result = civicrm_api('ContributionRecur','getcount', $params);
    if (!empty($result)) {
      $this->assign('jobLastRunWarning', '1');
      $params['api_action'] = 'IatsRecurringContributions';
      $job = civicrm_api('Job','getSingle',$params);
      $last_run = isset($job['last_run']) ? strtotime($job['last_run']) : '';
      $this->assign('jobLastRun', $job['last_run']);
      $this->assign('jobOverdue', '');
      $overdueHours  = (time() - $last_run) / (60 * 60);
      if (24 < $overdueHours) {
        $this->assign('jobOverdue', $overdueHours);
      }
    }
    // Load the most recent requests and responses from the log files
    foreach(array('cc','auth_result') as $key) {
      $search[$key] = empty($_GET['search_'.$key]) ? '' : filter_var($_GET['search_'.$key],FILTER_SANITIZE_STRING);
    }
    $log = $this->getLog($search);
    // $log[] = array('cc' => 'test', 'ip' => 'whatever', 'auth_result' => 'blah');
    $this->assign('iATSLog', $log);
    $this->assign('search', $search);
    parent::run();
  }

  function getLog($search = array(), $n = 40) {
    // avoid sql injection attacks
    $n = (int) $n;
    $filter = array();
    foreach($search as $key => $value) {
      if (!empty($value)) {
        $filter[] = "$key RLIKE '$value'";
      }
    }
    $where = empty($filter) ?  '' : " WHERE ".implode(' AND ',$filter);
    $sql = "SELECT * FROM civicrm_iats_request_log request LEFT JOIN civicrm_iats_response_log response ON request.invoice_num = response.invoice_num $where ORDER BY request.id DESC LIMIT $n";
    $dao = CRM_Core_DAO::executeQuery($sql);
    $log = array();
    $params = array('version' => 3, 'sequential' => 1, 'return' => 'contribution_id');
    $className = get_class($dao);
    $internal = array_keys(get_class_vars($className));
    while ($dao->fetch()) {
      $entry = get_object_vars($dao);
      unset($entry['']); // ghost entry!
      foreach($internal as $key) { // remove internal fields
        unset($entry[$key]);
      }
      $params['invoice_id'] = $entry['invoice_num'];
      $result = civicrm_api('Contribution','getsingle', $params);
      if (!empty($result['contribution_id'])) {
        $entry += $result;
        $entry['contributionURL'] = CRM_Utils_System::url('civicrm/contact/view/contribution', 'reset=1&id='.$entry['contribution_id'].'&cid='.$entry['contact_id'].'&action=view&selectedChild=Contribute');
      }
      $log[] = $entry;
    }
    return $log;
  }
}
