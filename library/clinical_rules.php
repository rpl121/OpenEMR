<?php
// Copyright (C) 2011 by following authors:
//   -Brady Miller <brady@sparmy.com>
//   -Ensofttek, LLC
//   -Medical Information Integration, LLC
//
// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.

// Functions are kept here that will support the clinical rules.

require_once(dirname(__FILE__) . "/patient.inc");
require_once(dirname(__FILE__) . "/forms.inc");
require_once(dirname(__FILE__) . "/formdata.inc.php");
require_once(dirname(__FILE__) . "/options.inc.php");

// Display the clinical summary widget.
// Parameters:
//   $patient_id - pid of selected patient
//   $mode       - choose either 'reminders-all' or 'reminders-due' (required)
//   $dateTarget - target date. If blank then will test with current date as target.
//   $organize_mode - Way to organize the results (default or plans)
function clinical_summary_widget($patient_id,$mode,$dateTarget='',$organize_mode='default') {
  
  // Set date to current if not set
  $dateTarget = ($dateTarget) ? $dateTarget : date('Y-m-d H:i:s');

  // Collect active actions
  $actions = test_rules_clinic('','passive_alert',$dateTarget,$mode,$patient_id,'',$organize_mode);

  // Display the actions
  foreach ($actions as $action) {

    // Deal with plan names first
    if (isset($action['is_plan']) &&$action['is_plan'])  {
      echo "<br><b>";
      echo htmlspecialchars( xl("Plan"), ENT_NOQUOTES) . ": ";
      echo generate_display_field(array('data_type'=>'1','list_id'=>'clinical_plans'),$action['id']);
      echo "</b><br>";
      continue;
    }

    if ($action['custom_flag']) {
      // Start link for reminders that use the custom rules input screen
      echo "<a href='../rules/patient_data.php?category=" .
        htmlspecialchars( $action['category'], ENT_QUOTES) . "&item=" . 
        htmlspecialchars( $action['item'], ENT_QUOTES) . 
        "' class='iframe  medium_modal' onclick='top.restoreSession()'>";
    }
    else if ($action['clin_rem_link']) {
      // Start link for reminders that use the custom rules input screen
      echo "<a href='../../../" . $action['reminder_message'] .
        "' class='iframe  medium_modal' onclick='top.restoreSession()'>";
    }
    else {
      // continue, since no link will be created
    }

    // Display Reminder Details
    echo generate_display_field(array('data_type'=>'1','list_id'=>'rule_action_category'),$action['category']) .
      ": " . generate_display_field(array('data_type'=>'1','list_id'=>'rule_action'),$action['item']);

    if ($action['custom_flag'] || $action['clin_rem_link']) {
      // End link for reminders that use an html link
      echo "</a>";
    }

    // Display due status
    if ($action['due_status']) {
      // Color code the status (red for past due, purple for due, green for not due and black for soon due)
      if ($action['due_status'] == "past_due") {
        echo "&nbsp;&nbsp;(<span style='color:red'>";
      }
      else if ($action['due_status'] == "due") {
        echo "&nbsp;&nbsp;(<span style='color:purple'>";
      }
      else if ($action['due_status'] == "not_due") {
        echo "&nbsp;&nbsp;(<span style='color:green'>";
      }
      else {
        echo "&nbsp;&nbsp;(<span>";
      }
      echo generate_display_field(array('data_type'=>'1','list_id'=>'rule_reminder_due_opt'),$action['due_status']) . "</span>)<br>";
    }
    else {
      echo "<br>";
    }

  }
}

// Display the active screen reminder.
// Parameters:
//   $patient_id - pid of selected patient
//   $mode       - choose either 'reminders-all' or 'reminders-due' (required)
//   $dateTarget - target date. If blank then will test with current date as target.
//   $organize_mode - Way to organize the results (default or plans)
function active_alert_summary($patient_id,$mode,$dateTarget='',$organize_mode='default') {

  // Set date to current if not set
  $dateTarget = ($dateTarget) ? $dateTarget : date('Y-m-d H:i:s');

  // Collect active actions
  $actions = test_rules_clinic('','active_alert',$dateTarget,$mode,$patient_id,'',$organize_mode);

  if (empty($actions)) {
    return false;
  }

  $returnOutput = "";

  // Display the actions
  foreach ($actions as $action) {

    // Deal with plan names first
    if ($action['is_plan']) {
      $returnOutput .= "<br><b>";
      $returnOutput .= htmlspecialchars( xl("Plan"), ENT_NOQUOTES) . ": ";
      $returnOutput .= generate_display_field(array('data_type'=>'1','list_id'=>'clinical_plans'),$action['id']);
      $returnOutput .= "</b><br>";
      continue;
    }

    // Display Reminder Details
    $returnOutput .= generate_display_field(array('data_type'=>'1','list_id'=>'rule_action_category'),$action['category']) .
      ": " . generate_display_field(array('data_type'=>'1','list_id'=>'rule_action'),$action['item']);

    // Display due status
    if ($action['due_status']) {
      // Color code the status (red for past due, purple for due, green for not due and black for soon due)
      if ($action['due_status'] == "past_due") {
        $returnOutput .= "&nbsp;&nbsp;(<span style='color:red'>";
      }
      else if ($action['due_status'] == "due") {
        $returnOutput .= "&nbsp;&nbsp;(<span style='color:purple'>";
      }
      else if ($action['due_status'] == "not_due") {
        $returnOutput .= "&nbsp;&nbsp;(<span style='color:green'>";
      }
      else {
        $returnOutput .= "&nbsp;&nbsp;(<span>";
      }
        $returnOutput .= generate_display_field(array('data_type'=>'1','list_id'=>'rule_reminder_due_opt'),$action['due_status']) . "</span>)<br>";
    }
    else {
      $returnOutput .= "<br>";
    }
  }
  return $returnOutput;
}

// Test the clinic rules of entire clinic and create a report or patient reminders
//  (can also test on one patient or patients of one provider)
// Parameters:
//   $provider   - id of a selected provider. If blank, then will test entire clinic. If 'collate_outer' or
//                   'collate_inner', then will test each provider in entire clinic; outer will nest plans
//                   inside collated providers, while inner will nest the providers inside the plans (note
//                   inner and outer are only different if organize_mode is set to plans).
//   $type       - rule filter (active_alert,passive_alert,cqm,amc,patient_reminder). If blank then will test all rules.
//   $dateTarget - target date. If blank then will test with current date as target.
//                              If an array, then is holding two dates ('dateBegin' and 'dateTarget')
//   $mode       - choose either 'report' or 'reminders-all' or 'reminders-due' (required)
//   $patient_id - pid of patient. If blank then will check all patients.
//   $plan       - test for specific plan only
//   $organize_mode - Way to organize the results (default, plans)
//     'default':
//       Returns a two-dimensional array of results organized by rules:
//         reminders-due mode - returns an array of reminders (action array elements plus a 'pid' and 'due_status')
//         reminders-all mode - returns an array of reminders (action array elements plus a 'pid' and 'due_status')
//         report mode    - returns an array of rows for the Clinical Quality Measures (CQM) report
//     'plans':
//       Returns similar to default, but organizes by the active plans
//  $options - can hold various option (for now, used to hold the manual number of labs for the AMC report)
//
function test_rules_clinic($provider='',$type='',$dateTarget='',$mode='',$patient_id='',$plan='',$organize_mode='default',$options=array()) {

  // If dateTarget is an array, then organize them.
  if (is_array($dateTarget)) {
    $dateArray = $dateTarget;
    $dateTarget = $dateTarget['dateTarget'];
  }

  // Set date to current if not set
  $dateTarget = ($dateTarget) ? $dateTarget : date('Y-m-d H:i:s');

  // Prepare the results array
  $results = array();

  // If set the $provider to collate_outer (or collate_inner without plans organize mode),
  // then run through this function recursively and return results.
  if (($provider == "collate_outer") || ($provider == "collate_inner" && $organize_mode != 'plans')) {
    // First, collect an array of all providers
    $query = "SELECT id, lname, fname, npi, federaltaxid FROM users WHERE authorized = 1 ORDER BY lname, fname"; 
    $ures = sqlStatement($query);
    // Second, run through each provider recursively
    while ($urow = sqlFetchArray($ures)) {
      $newResults = test_rules_clinic($urow['id'],$type,$dateTarget,$mode,$patient_id,$plan,$organize_mode);
      if (!empty($newResults)) {
        $provider_item['is_provider'] = TRUE;
        $provider_item['prov_lname'] = $urow['lname'];
        $provider_item['prov_fname'] = $urow['fname'];
        $provider_item['npi'] = $urow['npi'];
        $provider_item['federaltaxid'] = $urow['federaltaxid'];
        array_push($results,$provider_item);
        $results = array_merge($results,$newResults);
      }
    }
    // done, so now can return results
    return $results;
  }

  // If set organize-mode to plans, then collects active plans and run through this
  // function recursively and return results.
  if ($organize_mode == "plans") {
    // First, collect active plans
    $plans_resolve = resolve_plans_sql($plan,$patient_id);
    // Second, run through function recursively
    foreach ($plans_resolve as $plan_item) {
      //  (if collate_inner, then nest a collation of providers within each plan)
      if ($provider == "collate_inner") {
        // First, collect an array of all providers
        $query = "SELECT id, lname, fname, npi, federaltaxid FROM users WHERE authorized = 1 ORDER BY lname, fname";
        $ures = sqlStatement($query);
        // Second, run through each provider recursively
        $provider_results = array();
        while ($urow = sqlFetchArray($ures)) {
          $newResults = test_rules_clinic($urow['id'],$type,$dateTarget,$mode,$patient_id,$plan_item['id']);
          if (!empty($newResults)) {
            $provider_item['is_provider'] = TRUE;
            $provider_item['prov_lname'] = $urow['lname'];
            $provider_item['prov_fname'] = $urow['fname'];
            $provider_item['npi'] = $urow['npi'];
            $provider_item['federaltaxid'] = $urow['federaltaxid'];
            array_push($provider_results,$provider_item);
            $provider_results = array_merge($provider_results,$newResults);
          }
        }
        if (!empty($provider_results)) {
          $plan_item['is_plan'] = TRUE;
          array_push($results,$plan_item);
          $results = array_merge($results,$provider_results);
        }
      }
      else {
        // (not collate_inner, so do not nest providers within each plan)
        $newResults = test_rules_clinic($provider,$type,$dateTarget,$mode,$patient_id,$plan_item['id']);
        if (!empty($newResults)) {
          $plan_item['is_plan'] = TRUE;
          array_push($results,$plan_item);
          $results = array_merge($results,$newResults);
        }
      }
    }
    // done, so now can return results
    return $results;
  }

  // Collect all patient ids
  $patientData = array();
  if (!empty($patient_id)) {
    // only look at the selected patient
    $patientData[0]['pid'] = $patient_id;
  }
  else {
    if (empty($provider)) {
      // Look at entire practice
      $rez = sqlStatement("SELECT `pid` FROM `patient_data`");
      for($iter=0; $row=sqlFetchArray($rez); $iter++) {
       $patientData[$iter]=$row;
      } 
    }
    else {
      // Look at one provider
      $rez = sqlStatement("SELECT `pid` FROM `patient_data` " .
        "WHERE providerID=?", array($provider) );
      for($iter=0; $row=sqlFetchArray($rez); $iter++) {
       $patientData[$iter]=$row;
      }
    }
  }
  // Go through each patient(s)
  //
  //  If in report mode, then tabulate for each rule:
  //    Total Patients
  //    Patients that pass the filter
  //    Patients that pass the target
  //  If in reminders mode, then create reminders for each rule:
  //    Reminder that action is due soon
  //    Reminder that action is due
  //    Reminder that action is post-due
  
  //Collect applicable rules
  // Note that due to a limitation in the this function, the patient_id is explicitly
  //  for grouping items when not being done in real-time or for official reporting.
  //  So for cases such as patient reminders on a clinic scale, the calling function
  //  will actually need rather than pass in a explicit patient_id for each patient in
  //  a separate call to this function. 
  if ($mode != "report") {
    // Use per patient custom rules (if exist)
    // Note as discussed above, this only works for single patient instances.
    $rules = resolve_rules_sql($type,$patient_id,FALSE,$plan);
  }
  else { // $mode = "report"
    // Only use default rules (do not use patient custom rules)
    $rules = resolve_rules_sql($type,$patient_id,FALSE,$plan);
  }

  foreach( $rules as $rowRule ) {

    // If using cqm or amc type, then use the hard-coded rules set.
    // Note these rules are only used in report mode.
    if ($rowRule['cqm_flag'] || $rowRule['amc_flag']) {

      require_once( dirname(__FILE__)."/classes/rulesets/ReportManager.php");
      $manager = new ReportManager();
      if ($rowRule['amc_flag']) {
        // Send array of dates ('dateBegin' and 'dateTarget')
        $tempResults = $manager->runReport( $rowRule, $patientData, $dateArray, $options );
      }
      else {
        // Send target date
        $tempResults = $manager->runReport( $rowRule, $patientData, $dateTarget );
      }
      if (!empty($tempResults)) {
        foreach ($tempResults as $tempResult) {
          array_push($results,$tempResult);
        }
      }
 
      // Go on to the next rule
      continue;
    }

    // If in reminder mode then need to collect the measurement dates
    //  from rule_reminder table
    $target_dates = array();
    if ($mode != "report") {
      // Calculate the dates to check for
      if ($type == "patient_reminder") {
        $reminder_interval_type = "patient_reminder";
      }
      else { // $type == "passive_alert" or $type == "active_alert"
        $reminder_interval_type = "clinical_reminder";
      }
      $target_dates = calculate_reminder_dates($rowRule['id'], $dateTarget, $reminder_interval_type);
    }
    else { // $mode == "report"
      // Only use the target date in the report
      $target_dates[0] = $dateTarget;
    }

    //Reset the counters
    $total_patients = 0;
    $pass_filter = 0;
    $exclude_filter = 0;
    $pass_target = 0;

    // Find the number of target groups
    $targetGroups = returnTargetGroups($rowRule['id']);

    if ( (count($targetGroups) == 1) || ($mode == "report") ) {
      //skip this section if not report and more than one target group
      foreach( $patientData as $rowPatient ) {

        // Count the total patients
        $total_patients++;

        $dateCounter = 1; // for reminder mode to keep track of which date checking
        foreach ( $target_dates as $dateFocus ) {

          //Skip if date is set to SKIP
          if ($dateFocus == "SKIP") {
            $dateCounter++;
            continue;
          }

          //Set date counter and reminder token (applicable for reminders only)
          if ($dateCounter == 1) {
            $reminder_due = "soon_due";
          }
          else if ($dateCounter == 2) {
            $reminder_due = "due";
          }
          else { // $dateCounter == 3
            $reminder_due = "past_due";
          }

          // First, deal with deceased patients
          //  (for now will simply not pass the filter, but can add a database item
          //   if ever want to create rules for dead people)
          // Could also place this function at the total_patients level if wanted.
          //  (But then would lose the option of making rules for dead people)
          // Note using the dateTarget rather than dateFocus
          if (is_patient_deceased($rowPatient['pid'],$dateTarget)) {
            continue;
          }

          // Check if pass filter
          $passFilter = test_filter($rowPatient['pid'],$rowRule['id'],$dateFocus);
          if ($passFilter === "EXCLUDED") {
            // increment EXCLUDED and pass_filter counters
            //  and set as FALSE for reminder functionality.
            $pass_filter++;
            $exclude_filter++;
            $passFilter = FALSE;
          }
          if ($passFilter) {
            // increment pass filter counter
            $pass_filter++;
          }
          else {
            $dateCounter++;
            continue;
          }

          // Check if pass target
          $passTarget = test_targets($rowPatient['pid'],$rowRule['id'],'',$dateFocus); 
          if ($passTarget) {
            // increment pass target counter
            $pass_target++;
            // send to reminder results
            if ($mode == "reminders-all") {
              // place the completed actions into the reminder return array
              $actionArray = resolve_action_sql($rowRule['id'],'1');
              foreach ($actionArray as $action) {
                $action_plus = $action;
                $action_plus['due_status'] = "not_due";
                $action_plus['pid'] = $rowPatient['pid'];
                $results = reminder_results_integrate($results, $action_plus);
              }
            }
            break;
          }
          else {
            // send to reminder results
            if ($mode != "report") {
              // place the uncompleted actions into the reminder return array
              $actionArray = resolve_action_sql($rowRule['id'],'1');
              foreach ($actionArray as $action) {
                $action_plus = $action;
                $action_plus['due_status'] = $reminder_due;
                $action_plus['pid'] = $rowPatient['pid'];
                $results = reminder_results_integrate($results, $action_plus);
              }
            }
          }
          $dateCounter++;
        }
      }
    }

    // Calculate and save the data for the rule
    $percentage = calculate_percentage($pass_filter,$exclude_filter,$pass_target);
    if ($mode == "report") {
      $newRow=array('is_main'=>TRUE,'total_patients'=>$total_patients,'excluded'=>$exclude_filter,'pass_filter'=>$pass_filter,'pass_target'=>$pass_target,'percentage'=>$percentage);
      $newRow=array_merge($newRow,$rowRule);
      array_push($results, $newRow);
    }

    // Now run through the target groups if more than one
    if (count($targetGroups) > 1) {
      foreach ($targetGroups as $i) {

        //Reset the target counter
        $pass_target = 0;

        foreach( $patientData as $rowPatient ) {

          $dateCounter = 1; // for reminder mode to keep track of which date checking
          foreach ( $target_dates as $dateFocus ) {

            //Skip if date is set to SKIP
            if ($dateFocus == "SKIP") {
              $dateCounter++;
              continue;
            }

            //Set date counter and reminder token (applicable for reminders only)
            if ($dateCounter == 1) {
              $reminder_due = "soon_due";
            }
            else if ($dateCounter == 2) {
              $reminder_due = "due";
            }
            else { // $dateCounter == 3
              $reminder_due = "past_due";
            }    

            // First, deal with deceased patients
            //  (for now will simply not pass the filter, but can add a database item
            //   if ever want to create rules for dead people)
            // Could also place this function at the total_patients level if wanted.
            //  (But then would lose the option of making rules for dead people)
            // Note using the dateTarget rather than dateFocus
            if (is_patient_deceased($rowPatient['pid'],$dateTarget)) {
              continue;
            }

            // Check if pass filter
            $passFilter = test_filter($rowPatient['pid'],$rowRule['id'],$dateFocus);
            if ($passFilter === "EXCLUDED") {
              $passFilter = FALSE;
            }
            if (!$passFilter) {
              // increment pass filter counter
              $dateCounter++;
              continue;
            }

            //Check if pass target
            $passTarget = test_targets($rowPatient['pid'],$rowRule['id'],$i,$dateFocus);
            if ($passTarget) {
              // increment pass target counter
              $pass_target++;
              // send to reminder results
              if ($mode == "reminders-all") {
                // place the completed actions into the reminder return array
                $actionArray = resolve_action_sql($rowRule['id'],$i);
                foreach ($actionArray as $action) {
                  $action_plus = $action;
                  $action_plus['due_status'] = "not_due";
                  $action_plus['pid'] = $rowPatient['pid'];
                  $results = reminder_results_integrate($results, $action_plus);
                }
              }
              break;
            }
            else {
              // send to reminder results
              if ($mode != "report") {
                // place the actions into the reminder return array
                $actionArray = resolve_action_sql($rowRule['id'],$i);
                foreach ($actionArray as $action) {
                  $action_plus = $action;
                  $action_plus['due_status'] = $reminder_due;
                  $action_plus['pid'] = $rowPatient['pid'];
                  $results = reminder_results_integrate($results, $action_plus);
                }
              }
            }
            $dateCounter++;
          }
        }

        // Calculate and save the data for the rule
          $percentage = calculate_percentage($pass_filter,$exclude_filter,$pass_target);

        // Collect action for title (just use the first one, if more than one)
        $actionArray = resolve_action_sql($rowRule['id'],$i);
        $action = $actionArray[0];
        if ($mode == "report") {
          $newRow=array('is_sub'=>TRUE,'action_category'=>$action['category'],'action_item'=>$action['item'],'total_patients'=>'','excluded'=>'','pass_filter'=>'','pass_target'=>$pass_target,'percentage'=>$percentage);
          array_push($results, $newRow);
        }
      }
    }
  }

  // Return the data
  return $results;
}

// Test filter of a selected rule on a selected patient
// Parameters:
//   $patient_id - pid of selected patient.
//   $rule       - id(string) of selected rule
//   $dateTarget - target date.
// Return:
//   boolean (if pass filter then TRUE, if excluded then 'EXCLUDED', if not pass filter then FALSE)
function test_filter($patient_id,$rule,$dateTarget) {

  // Set date to current if not set
  $dateTarget = ($dateTarget) ? $dateTarget : date('Y-m-d H:i:s');

  // Collect patient information
  $patientData = getPatientData($patient_id, "sex, DATE_FORMAT(DOB,'%Y %m %d') as DOB_TS");

  //
  // ----------------- INCLUSIONS -----------------
  //

  // -------- Age Filter (inclusion) ------------
  // Calculate patient age in years and months
  $patientAgeYears = convertDobtoAgeYearDecimal($patientData['DOB_TS'],$dateTarget);
  $patientAgeMonths = convertDobtoAgeMonthDecimal($patientData['DOB_TS'],$dateTarget);

  // Min age (year) Filter (assume that there in not more than one of each)
  $filter = resolve_filter_sql($rule,'filt_age_min');
  if (!empty($filter)) {
    $row = $filter[0];
    if ($row ['method_detail'] == "year") {
      if ( $row['value'] && ($row['value'] > $patientAgeYears) ) {
        return false;
      }
    }
    if ($row ['method_detail'] == "month") {
      if ( $row['value'] && ($row['value'] > $patientAgeMonths) ) {
        return false;
      }
    }
  }
  // Max age (year) Filter (assume that there in not more than one of each)
  $filter = resolve_filter_sql($rule,'filt_age_max');
  if (!empty($filter)) {
    $row = $filter[0];
    if ($row ['method_detail'] == "year") {
      if ( $row['value'] && ($row['value'] < $patientAgeYears) ) {
        return false;
      }
    }
    if ($row ['method_detail'] == "month") {
      if ( $row['value'] && ($row['value'] < $patientAgeMonths) ) {
        return false;
      }
    }
  }

  // -------- Gender Filter (inclusion) ---------
  // Gender Filter (assume that there in not more than one of each)
  $filter = resolve_filter_sql($rule,'filt_sex');
  if (!empty($filter)) {
    $row = $filter[0];
    if ( $row['value'] && ($row['value'] != $patientData['sex']) ) {
      return false;
    }
  }

  // -------- Database Filter (inclusion) ------
  // Database Filter
  $filter = resolve_filter_sql($rule,'filt_database');
  if ((!empty($filter)) && !database_check($patient_id,$filter,'',$dateTarget)) return false;

  // -------- Lists Filter (inclusion) ----
  // Set up lists filter, which is fully customizable and currently includes diagnoses, meds,
  //   surgeries and allergies.
  $filter = resolve_filter_sql($rule,'filt_lists');
  if ((!empty($filter)) && !lists_check($patient_id,$filter,$dateTarget)) return false;

  // -------- Procedure (labs,imaging,test,procedures,etc) Filter (inlcusion) ----
  // Procedure Target (includes) (may need to include an interval in the future)
  $filter = resolve_filter_sql($rule,'filt_proc');
  if ((!empty($filter)) && !procedure_check($patient_id,$filter,'',$dateTarget)) return false;

  //
  // ----------------- EXCLUSIONS -----------------
  //

  // -------- Lists Filter (EXCLUSION) ----
  // Set up lists EXCLUSION filter, which is fully customizable and currently includes diagnoses, meds,
  //   surgeries and allergies.
  $filter = resolve_filter_sql($rule,'filt_lists',0);
  if ((!empty($filter)) && lists_check($patient_id,$filter,$dateTarget)) return "EXCLUDED";

  // Passed all filters, so return true.
  return true;
}

// Return an array containing existing group ids for a rule
// Parameters:
//   $rule - id(string) of rule
// Return:
//   array, listing of group ids
function returnTargetGroups($rule) {

  $sql = sqlStatement("SELECT DISTINCT `group_id` FROM `rule_target` " .
    "WHERE `id`=?", array($rule) );

  $groups = array();
  for($iter=0; $row=sqlFetchArray($sql); $iter++) {
    array_push($groups,$row['group_id']);
  }
  return $groups;
}

// Test targets of a selected rule on a selected patient
// Parameters:
//   $patient_id - pid of selected patient.
//   $rule       - id(string) of selected rule (if blank, then will ignore grouping)
//   $group_id   - group id of target group
//   $dateTarget - target date.
// Return:
//   boolean (if target passes then true, otherwise false)
function test_targets($patient_id,$rule,$group_id='',$dateTarget) {

  // -------- Interval Target ----
  $interval = resolve_target_sql($rule,$group_id,'target_interval');

  // -------- Database Target ----
  // Database Target (includes)
  $target = resolve_target_sql($rule,$group_id,'target_database');
  if ((!empty($target)) && !database_check($patient_id,$target,$interval,$dateTarget)) return false;

  // -------- Procedure (labs,imaging,test,procedures,etc) Target ----
  // Procedure Target (includes)
  $target = resolve_target_sql($rule,$group_id,'target_proc');
  if ((!empty($target)) && !procedure_check($patient_id,$target,$interval,$dateTarget)) return false;

  // -------- Appointment Target ----
  // Appointment Target (includes) (Specialized functionality for appointment reminders)
  $target = resolve_target_sql($rule,$group_id,'target_appt');
  if ((!empty($target)) && appointment_check($patient_id,$dateTarget)) return false;

  // Passed all target tests, so return true.
  return true;
}

// Function to return active plans
// Parameters:
//   $type             - plan type filter (normal or cqm or blank)
//   $patient_id       - pid of selected patient. (if custom plan does not exist then
//                        will use the default plan)
//   $configurableOnly - true if only want the configurable (per patient) plans
//                       (ie. ignore cqm plans)
// Return: array containing plans
function resolve_plans_sql($type='',$patient_id='0',$configurableOnly=FALSE) {

  if ($configurableOnly) {
    // Collect all default, configurable (per patient) plans into an array
    //   (ie. ignore the cqm rules)
    $sql = sqlStatement("SELECT * FROM `clinical_plans` WHERE `pid`=0 AND `cqm_flag` !=1 ORDER BY `id`");
  }
  else {
    // Collect all default plans into an array
    $sql = sqlStatement("SELECT * FROM `clinical_plans` WHERE `pid`=0 ORDER BY `id`");
  }
  $returnArray= array();
  for($iter=0; $row=sqlFetchArray($sql); $iter++) {
    array_push($returnArray,$row);
  }

  // Now collect the pertinent plans
  $newReturnArray = array();

  // Need to select rules (use custom if exist)
  foreach ($returnArray as $plan) {
    $customPlan = sqlQuery("SELECT * FROM `clinical_plans` WHERE `id`=? AND `pid`=?", array($plan['id'],$patient_id) );

    // Decide if use default vs custom plan (preference given to custom plan)
    if (!empty($customPlan)) {
      if ($type == "cqm" ) {
        // For CQM , do not use custom plans (these are to create standard clinic wide reports)
        $goPlan = $plan;
      }
      else {
        // merge the custom plan with the default plan
        $mergedPlan = array();
        foreach ($customPlan as $key => $value) {
          if ($value == NULL && preg_match("/_flag$/",$key)) {
            // use default setting
            $mergedPlan[$key] = $plan[$key];
          }
          else {
            // use custom setting
            $mergedPlan[$key] = $value;
          }
        }
        $goPlan = $mergedPlan;
      }
    }
    else {
      $goPlan = $plan;
    }

    // Use the chosen plan if set
    if (!empty($type)) {
      if ($goPlan["${type}_flag"] == 1) {
        // active, so use the plan
        array_push($newReturnArray,$goPlan);
      }
    }
    else {
      if ($goPlan['normal_flag'] == 1 ||
          $goPlan['cqm_flag'] == 1) {
        // active, so use the plan
        array_push($newReturnArray,$goPlan);
      }
    }
  }
  $returnArray = $newReturnArray;

  return $returnArray;
}

// Function to return a specific plan
// Parameters:
//   $plan       - id(string) of plan
//   $patient_id - pid of selected patient. (if set to 0, then will return
//                 the default rule).
// Return: array containing a rule
function collect_plan($plan,$patient_id='0') {

  return sqlQuery("SELECT * FROM `clinical_plans` WHERE `id`=? AND `pid`=?", array($plan,$patient_id) );

}

// Function to set a specific plan activity for a specific patient
// Parameters:
//   $plan       - id(string) of plan
//   $type       - plan filter (normal,cqm)
//   $setting    - activity of plan (yes,no,default)
//   $patient_id - pid of selected patient.
// Return: nothing
function set_plan_activity_patient($plan,$type,$setting,$patient_id) {

  // Don't allow messing with the default plans here
  if ($patient_id == "0") {
    return;
  }

  // Convert setting
  if ($setting == "on") {
    $setting = 1;
  }
  else if ($setting == "off") {
    $setting = 0;
  }
  else { // $setting == "default"
    $setting = NULL;
  }

  // Collect patient specific plan, if already exists.
  $query = "SELECT * FROM `clinical_plans` WHERE `id` = ? AND `pid` = ?";
  $patient_plan = sqlQuery($query, array($plan,$patient_id) );

  if (empty($patient_plan)) {
    // Create a new patient specific plan with flags all set to default
    $query = "INSERT into `clinical_plans` (`id`, `pid`) VALUES (?,?)";
    sqlStatement($query, array($plan, $patient_id) );
  }

  // Update patient specific row
  $query = "UPDATE `clinical_plans` SET `" . add_escape_custom($type) . "_flag`= ? WHERE id = ? AND pid = ?";
  sqlStatement($query, array($setting,$plan,$patient_id) );

}

// Function to return active rules
// Parameters:
//   $type             - rule filter (active_alert,passive_alert,cqm,amc,patient_reminder)
//   $patient_id       - pid of selected patient. (if custom rule does not exist then
//                        will use the default rule)
//   $configurableOnly - true if only want the configurable (per patient) rules 
//                       (ie. ignore cqm and amc rules)
//   $plan             - collect rules for specific plan
// Return: array containing rules
function resolve_rules_sql($type='',$patient_id='0',$configurableOnly=FALSE,$plan='') {

  if ($configurableOnly) {
    // Collect all default, configurable (per patient) rules into an array
    //   (ie. ignore the cqm and amc rules)
    $sql = sqlStatement("SELECT * FROM `clinical_rules` WHERE `pid`=0 AND `cqm_flag` !=1 AND `amc_flag` !=1 ORDER BY `id`");
  }
  else {
    // Collect all default rules into an array
    $sql = sqlStatement("SELECT * FROM `clinical_rules` WHERE `pid`=0 ORDER BY `id`");
  }
  $returnArray= array();
  for($iter=0; $row=sqlFetchArray($sql); $iter++) {
    array_push($returnArray,$row);
  }

  // Now filter rules for plan (if applicable)
  if (!empty($plan)) {
    $planReturnArray = array();
    foreach ($returnArray as $rule) {
      $standardRule = sqlQuery("SELECT * FROM `clinical_plans_rules` " .
                               "WHERE `plan_id`=? AND `rule_id`=?", array($plan,$rule['id']) );
      if (!empty($standardRule)) {
        array_push($planReturnArray,$rule);
      }
    }
    $returnArray = $planReturnArray;
  }

  // Now collect the pertinent rules
  $newReturnArray = array();

  // Need to select rules (use custom if exist)
  foreach ($returnArray as $rule) {
    $customRule = sqlQuery("SELECT * FROM `clinical_rules` WHERE `id`=? AND `pid`=?", array($rule['id'],$patient_id) );

    // Decide if use default vs custom rule (preference given to custom rule)
    if (!empty($customRule)) {
      if ($type == "cqm" || $type == "amc" ) {
        // For CQM and AMC, do not use custom rules (these are to create standard clinic wide reports)
        $goRule = $rule;
      }
      else {
        // merge the custom rule with the default rule
        $mergedRule = array();
        foreach ($customRule as $key => $value) {
          if ($value == NULL && preg_match("/_flag$/",$key)) {
            // use default setting
            $mergedRule[$key] = $rule[$key];
          }
          else {
            // use custom setting
            $mergedRule[$key] = $value;
          }
        }
        $goRule = $mergedRule;
      }
    }
    else {
      $goRule = $rule;
    }

    // Use the chosen rule if set
    if (!empty($type)) {
      if ($goRule["${type}_flag"] == 1) {
        // active, so use the rule
        array_push($newReturnArray,$goRule);
      }
    }
    else {
      // no filter, so return the rule
      array_push($newReturnArray,$goRule);
    }
  }
  $returnArray = $newReturnArray;

  return $returnArray;
}

// Function to return a specific rule
// Parameters:
//   $rule       - id(string) of rule
//   $patient_id - pid of selected patient. (if set to 0, then will return
//                 the default rule).
// Return: array containing a rule
function collect_rule($rule,$patient_id='0') {

  return sqlQuery("SELECT * FROM `clinical_rules` WHERE `id`=? AND `pid`=?", array($rule,$patient_id) );
  
}

// Function to set a specific rule activity for a specific patient
// Parameters:
//   $rule       - id(string) of rule
//   $type       - rule filter (active_alert,passive_alert,cqm,amc,patient_reminder)
//   $setting    - activity of rule (yes,no,default)
//   $patient_id - pid of selected patient.
// Return: nothing
function set_rule_activity_patient($rule,$type,$setting,$patient_id) {

  // Don't allow messing with the default rules here
  if ($patient_id == "0") {
    return;
  }

  // Convert setting
  if ($setting == "on") {
    $setting = 1;
  }
  else if ($setting == "off") {
    $setting = 0;
  }
  else { // $setting == "default"
    $setting = NULL;
  }

  // Collect patient specific rule, if already exists.
  $query = "SELECT * FROM `clinical_rules` WHERE `id` = ? AND `pid` = ?";
  $patient_rule = sqlQuery($query, array($rule,$patient_id) );

  if (empty($patient_rule)) {
    // Create a new patient specific rule with flags all set to default
    $query = "INSERT into `clinical_rules` (`id`, `pid`) VALUES (?,?)";
    sqlStatement($query, array($rule, $patient_id) ); 
  }

  // Update patient specific row
  $query = "UPDATE `clinical_rules` SET `" . add_escape_custom($type) . "_flag`= ? WHERE id = ? AND pid = ?";
  sqlStatement($query, array($setting,$rule,$patient_id) );

}

// Function to return applicable reminder dates (relative)
// Parameters:
//   $rule            - id(string) of selected rule
//   $reminder_method - string label of filter type
// Return: array containing reminder features
function resolve_reminder_sql($rule,$reminder_method) {
  $sql = sqlStatement("SELECT `method_detail`, `value` FROM `rule_reminder` " .
    "WHERE `id`=? AND `method`=?", array($rule, $reminder_method) );

  $returnArray= array();
  for($iter=0; $row=sqlFetchArray($sql); $iter++) {
    array_push($returnArray,$row);
  }
  return $returnArray;
}

// Function to return applicable filters
// Parameters:
//   $rule          - id(string) of selected rule
//   $filter_method - string label of filter type
//   $include_flag  - to allow selection for included or excluded filters
// Return: array containing filters
function resolve_filter_sql($rule,$filter_method,$include_flag=1) {
  $sql = sqlStatement("SELECT `method_detail`, `value`, `required_flag` FROM `rule_filter` " .
    "WHERE `id`=? AND `method`=? AND `include_flag`=?", array($rule, $filter_method, $include_flag) );

  $returnArray= array();
  for($iter=0; $row=sqlFetchArray($sql); $iter++) {
    array_push($returnArray,$row);
  }
  return $returnArray;
}

// Function to return applicable targets
// Parameters:
//   $rule          - id(string) of selected rule
//   $group_id      - group id of target group (if blank, then will ignore grouping)
//   $target_method - string label of target type
//   $include_flag  - to allow selection for included or excluded targets
// Return: array containing targets
function resolve_target_sql($rule,$group_id='',$target_method,$include_flag=1) {

  if ($group_id) {
    $sql = sqlStatement("SELECT `value`, `required_flag`, `interval` FROM `rule_target` " .
      "WHERE `id`=? AND `group_id`=? AND `method`=? AND `include_flag`=?", array($rule, $group_id, $target_method, $include_flag) );
  }
  else {
    $sql = sqlStatement("SELECT `value`, `required_flag`, `interval` FROM `rule_target` " .
      "WHERE `id`=? AND `method`=? AND `include_flag`=?", array($rule, $target_method, $include_flag) );
  }

  $returnArray= array();
  for($iter=0; $row=sqlFetchArray($sql); $iter++) {
    array_push($returnArray,$row);
  }
  return $returnArray;
}

// Function to return applicable actions
// Parameters:
//   $rule          - id(string) of selected rule
//   $group_id      - group id of target group (if blank, then will ignore grouping)
// Return: array containing actions
function resolve_action_sql($rule,$group_id='') {

  if ($group_id) {
    $sql = sqlStatement("SELECT b.category, b.item, b.clin_rem_link, b.reminder_message, b.custom_flag " .
      "FROM `rule_action` as a " .
      "JOIN `rule_action_item` as b " .
      "ON a.category = b.category AND a.item = b.item " .
      "WHERE a.id=? AND a.group_id=?", array($rule,$group_id) );
  }
  else {
    $sql = sqlStatement("SELECT b.category, b.item, b.value, b.custom_flag " .
      "FROM `rule_action` as a " .
      "JOIN `rule_action_item` as b " .
      "ON a.category = b.category AND a.item = b.item " .
      "WHERE a.id=?", array($rule) );
  }

  $returnArray= array();
  for($iter=0; $row=sqlFetchArray($sql); $iter++) {
    array_push($returnArray,$row);
  }
  return $returnArray;
}

// Function to check database filters and targets
// Parameters:
//   $patient_id - pid of selected patient.
//   $filter     - array containing filter/target elements
//   $interval   - used for the interval elements
//   $dateTarget - target date. blank is current date.
// Return: boolean if check passed, otherwise false
function database_check($patient_id,$filter,$interval='',$dateTarget='') {
  $isMatch = false; //matching flag

  // Set date to current if not set
  $dateTarget = ($dateTarget) ? $dateTarget : date('Y-m-d H:i:s');

  // Unpackage interval information
  // (Assume only one for now and only pertinent for targets)
  $intervalType = '';
  $intervalValue = '';
  if (!empty($interval)) {
    $intervalType = $interval[0]['value'];
    $intervalValue = $interval[0]['interval'];
  }

  foreach( $filter as $row ) {
    // Row description
    //   [0]=>special modes
    $temp_df = explode("::",$row['value']);

    if ($temp_df[0] == "CUSTOM") {
      // Row description
      //   [0]=>special modes(CUSTOM) [1]=>category [2]=>item [3]=>complete? [4]=>number of hits comparison [5]=>number of hits
      if (exist_custom_item($patient_id, $temp_df[1], $temp_df[2], $temp_df[3], $temp_df[4], $temp_df[5], $intervalType, $intervalValue, $dateTarget)) {
        // Record the match
        $isMatch = true;
      }
      else {
       // If this is a required entry then return false
       if ($row['required_flag']) return false;
      }
    }
    else if ($temp_df[0] == "LIFESTYLE") {
      // Row description
      //   [0]=>special modes(LIFESTYLE) [1]=>column [2]=>status
      if (exist_lifestyle_item($patient_id, $temp_df[1], $temp_df[2], $dateTarget)) {
        // Record the match
        $isMatch = true;
      }
      else {
       // If this is a required entry then return false
       if ($row['required_flag']) return false;
      }  
    }
    else {
      // Default mode
      // Row description
      //   [0]=>special modes(BLANK) [1]=>table [2]=>column [3]=>value comparison [4]=>value [5]=>number of hits comparison [6]=>number of hits
      if (exist_database_item($patient_id, $temp_df[1], $temp_df[2], $temp_df[3], $temp_df[4], $temp_df[5], $temp_df[6], $intervalType, $intervalValue, $dateTarget)) {
        // Record the match
        $isMatch = true;
      }
      else {
       // If this is a required entry then return false
       if ($row['required_flag']) return false;
      }
    }
  }

  // return results of check
  return $isMatch;
}

// Function to check procedure filters and targets
// Parameters:
//   $patient_id - pid of selected patient.
//   $filter     - array containing filter/target elements
//   $interval   - used for the interval elements
//   $dateTarget - target date. blank is current date.
// Return: boolean if check passed, otherwise false
function procedure_check($patient_id,$filter,$interval='',$dateTarget='') {
  $isMatch = false; //matching flag

  // Set date to current if not set
  $dateTarget = ($dateTarget) ? $dateTarget : date('Y-m-d H:i:s');

  // Unpackage interval information
  // (Assume only one for now and only pertinent for targets)
  $intervalType = '';
  $intervalValue = '';
  if (!empty($interval)) {
    $intervalType = $interval[0]['value'];
    $intervalValue = $interval[0]['interval'];
  }

  foreach( $filter as $row ) {
    // Row description
    // [0]=>title [1]=>code [2]=>value comparison [3]=>value [4]=>number of hits comparison [5]=>number of hits
    //   code description
    //     <type(ICD9,CPT4)>:<identifier>||<type(ICD9,CPT4)>:<identifier>||<identifier> etc.
    $temp_df = explode("::",$row['value']);
    if (exist_procedure_item($patient_id, $temp_df[0], $temp_df[1], $temp_df[2], $temp_df[3], $temp_df[4], $temp_df[5], $intervalType, $intervalValue, $dateTarget)) {
      // Record the match
      $isMatch = true;
    }
    else {
      // If this is a required entry then return false
      if ($row['required_flag']) return false;
    }
  }

  // return results of check
  return $isMatch;
}

// Function to check for appointment
// Parameters:
//   $patient_id - pid of selected patient.
//   $dateTarget - target date.
// Return: boolean if appt exist, otherwise false
function appointment_check($patient_id,$dateTarget='') {
  $isMatch = false; //matching flag

  // Set date to current if not set (although should always be set)
  $dateTarget = ($dateTarget) ? $dateTarget : date('Y-m-d H:i:s');
  $dateTargetRound = date('Y-m-d',$dateTarget);

  // Set current date
  $currentDate = date('Y-m-d H:i:s');
  $currentDateRound = date('Y-m-d',$dateCurrent);

  // Basically, if the appointment is within the current date to the target date,
  //  then return true. (will not send reminders on same day as appointment)
  $sql = sqlStatement("SELECT openemr_postcalendar_events.pc_eid, " .
    "openemr_postcalendar_events.pc_title, " .
    "openemr_postcalendar_events.pc_eventDate, " .
    "openemr_postcalendar_events.pc_startTime, " .
    "openemr_postcalendar_events.pc_endTime " .
    "FROM openemr_postcalendar_events " .
    "WHERE openemr_postcalendar_events.pc_eventDate > ? " .
    "AND openemr_postcalendar_events.pc_eventDate <= ? " .
    "AND openemr_postcalendar_events.pc_pid = ?", array($currentDate,$dateTarget,$patient_id) );

  // return results of check
  //
  // TODO: Figure out how to have multiple appointment and changing appointment reminders.
  //         Plan to send back array of appt info (eid, time, date, etc.)
  //         to do this.
  if (sqlNumRows($sql) > 0) {
    $isMatch = true;
  }

  return $isMatch;
}

// Function to check lists filters and targets
//  Customizable and currently includes diagnoses, medications,
//    allergies and surgeries.
// Parameters:
//   $patient_id - pid of selected patient.
//   $filter     - array containing lists filter/target elements
//   $dateTarget - target date. blank is current date.
// Return: boolean if check passed, otherwise false
function lists_check($patient_id,$filter,$dateTarget) {
  $isMatch = false; //matching flag

  // Set date to current if not set
  $dateTarget = ($dateTarget) ? $dateTarget : date('Y-m-d H:i:s');

  foreach ( $filter as $row ) {
    if (exist_lists_item($patient_id, $row['method_detail'], $row['value'], $dateTarget)) {
      // Record the match
      $isMatch = true;
    }
    else {
     // If this is a required entry then return false
     if ($row['required_flag']) return false;
    }
  }

  // return results of check
  return $isMatch;
}

// Function to check for existance of data in database for a patient
// Parameters:
//   $patient_id    - pid of selected patient.
//   $table         - selected mysql table
//   $column        - selected mysql column
//   $data_comp       - data comparison (eq,ne,gt,ge,lt,le)
//   $data            - selected data in the mysql database
//   $num_items_comp  - number items comparison (eq,ne,gt,ge,lt,le)
//   $num_items_thres - number of items threshold
//   $intervalType  - type of interval (ie. year)
//   $intervalValue - searched for within this many times of the interval type
//   $dateTarget    - target date.
// Return: boolean if check passed, otherwise false
function exist_database_item($patient_id,$table,$column='',$data_comp,$data='',$num_items_comp,$num_items_thres,$intervalType='',$intervalValue='',$dateTarget='') {

  // Set date to current if not set
  $dateTarget = ($dateTarget) ? $dateTarget : date('Y-m-d H:i:s');

  // Collect the correct column label for patient id in the table
  $patient_id_label = collect_database_label('pid',$table);

  // Get the interval sql query string
  $dateSql = sql_interval_string($table,$intervalType,$intervalValue,$dateTarget);

  // If just checking for existence (ie. data is empty),
  //   then simply set the comparison operator to ne.
  if (empty($data)) {
    $data_comp = "ne";
  }

  // get the appropriate sql comparison operator
  $compSql = convertCompSql($data_comp);

  // check for items
  if (empty($column)) {
    // simple search for any table entries
    $sql = sqlStatement("SELECT * " .
      "FROM `" . add_escape_custom($table)  . "` " .
      "WHERE `" . add_escape_custom($patient_id_label)  . "`=?", array($patient_id) );
  }
  else {
    // search for number of specific items
    $sql = sqlStatement("SELECT `" . add_escape_custom($column) . "` " .
      "FROM `" . add_escape_custom($table)  . "` " .
      "WHERE `" . add_escape_custom($column) ."`" . $compSql . "? " .
      "AND `" . add_escape_custom($patient_id_label)  . "`=? " .
      $dateSql, array($data,$patient_id) );
  }

  // See if number of returned items passes the comparison
  return itemsNumberCompare($num_items_comp, $num_items_thres, sqlNumRows($sql));
}

// Function to check for existence of procedure(s) for a patient
// Parameters:
//   $patient_id      - pid of selected patient.
//   $proc_title      - procedure title
//   $proc_code       - procedure identifier code (array of <type(ICD9,CPT4)>:<identifier>||<type(ICD9,CPT4)>:<identifier>||<identifier> etc.)
//   $result_comp     - results comparison (eq,ne,gt,ge,lt,le)
//   $result_data     - results data
//   $num_items_comp  - number items comparison (eq,ne,gt,ge,lt,le)
//   $num_items_thres - number of items threshold
//   $intervalType    - type of interval (ie. year)
//   $intervalValue   - searched for within this many times of the interval type
//   $dateTarget      - target date.
// Return: boolean if check passed, otherwise false
function exist_procedure_item($patient_id,$proc_title,$proc_code,$result_comp,$result_data='',$num_items_comp,$num_items_thres,$intervalType='',$intervalValue='',$dateTarget='') {

  // Set date to current if not set
  $dateTarget = ($dateTarget) ? $dateTarget : date('Y-m-d H:i:s');

  // Set the table exception (for looking up pertinent date and pid sql columns)
  $table = "PROCEDURE-EXCEPTION";

  // Collect the correct column label for patient id in the table
  $patient_id_label = collect_database_label('pid',$table);

  // Get the interval sql query string
  $dateSql = sql_interval_string($table,$intervalType,$intervalValue,$dateTarget);

  // If just checking for existence (ie result_data is empty),
  //   then simply set the comparison operator to ne.
  if (empty($result_data)) {
    $result_comp = "ne";
  }
  
  // get the appropriate sql comparison operator
  $compSql = convertCompSql($result_comp);

  // explode the code array
  $codes= array();
  if (!empty($proc_code)) {
    $codes = explode("||",$proc_code);
  }
  else {
    $codes[0] = '';
  }

  // ensure proc_title is at least blank
  if (empty($proc_title)) {
    $proc_title = '';
  }

  // collect specific items (use both title and/or codes) that fulfill request
  $sqlBindArray=array();
  $sql_query = "SELECT procedure_result.result " .
               "FROM `procedure_type`, " .
               "`procedure_order`, " .
               "`procedure_report`, " .
               "`procedure_result` " .
               "WHERE procedure_type.procedure_type_id = procedure_order.procedure_type_id " .
               "AND procedure_order.procedure_order_id = procedure_report.procedure_order_id " .
               "AND procedure_report.procedure_report_id = procedure_result.procedure_report_id " .
               "AND ";
  foreach ($codes as $tem) {
    $sql_query .= "( ( (procedure_type.standard_code = ? AND procedure_type.standard_code != '') " .
                  "OR (procedure_type.procedure_code = ? AND procedure_type.procedure_code != '') ) OR ";
    array_push($sqlBindArray,$tem,$tem);
  }
  $sql_query .= "(procedure_type.name = ? AND procedure_type.name != '') ) " .
                "AND procedure_result.result " . $compSql . " ? " .
                "AND " . add_escape_custom($patient_id_label) . " = ? " . $dateSql;
  array_push($sqlBindArray,$proc_title,$result_data,$patient_id);
  $sql = sqlStatement($sql_query,$sqlBindArray);
 
  // See if number of returned items passes the comparison
  return itemsNumberCompare($num_items_comp, $num_items_thres, sqlNumRows($sql));
}

// Function to check for existance of data for a patient in the rule_patient_data table
// Parameters:
//   $patient_id    - pid of selected patient.
//   $category      - label in category column
//   $item          - label in item column
//   $complete       - label in complete column (YES,NO, or blank)
//   $num_items_comp  - number items comparison (eq,ne,gt,ge,lt,le)
//   $num_items_thres - number of items threshold
//   $intervalType  - type of interval (ie. year)
//   $intervalValue - searched for within this many times of the interval type
//   $dateTarget    - target date.
// Return: boolean if check passed, otherwise false
function exist_custom_item($patient_id,$category,$item,$complete,$num_items_comp,$num_items_thres,$intervalType='',$intervalValue='',$dateTarget) {

  // Set the table
  $table = 'rule_patient_data';

  // Collect the correct column label for patient id in the table
  $patient_id_label = collect_database_label('pid',$table);

  // Get the interval sql query string
  $dateSql = sql_interval_string($table,$intervalType,$intervalValue,$dateTarget);

  // search for number of specific items
  $sql = sqlStatement("SELECT `result` " .
    "FROM `" . add_escape_custom($table)  . "` " .
    "WHERE `category`=? " .
    "AND `item`=? " .
    "AND `complete`=? " .
    "AND `" . add_escape_custom($patient_id_label)  . "`=? " .
    $dateSql, array($category,$item,$complete,$patient_id) );

  // See if number of returned items passes the comparison
  return itemsNumberCompare($num_items_comp, $num_items_thres, sqlNumRows($sql));
}

// Function to check for existance of data for a patient in lifestyle section
// Parameters:
//   $patient_id - pid of selected patient.
//   $lifestyle  - selected label of mysql column of patient history
//   $status     - specific status of selected lifestyle element
//   $dateTarget - target date. blank is current date.
// Return: boolean if check passed, otherwise false
function exist_lifestyle_item($patient_id,$lifestyle,$status,$dateTarget) {

  // Set date to current if not set
  $dateTarget = ($dateTarget) ? $dateTarget : date('Y-m-d H:i:s');

  // Collect pertinent history data
  $history = getHistoryData($patient_id, $lifestyle,'',$dateTarget);
 
  // See if match
  $stringFlag = strstr($history[$lifestyle], "|".$status);
  if (empty($status)) {
    // Only ensuring any data has been entered into the field
    $stringFlag = true;
  }
  if ( $history[$lifestyle] &&
       $history[$lifestyle] != '|0|' &&
       $stringFlag ) {
    return true;
  }
  else {
    return false;
  }
}

// Function to check for lists item of a patient
//  Fully customizable and includes diagnoses, medications,
//    allergies, and surgeries.
// Parameters:
//   $patient_id - pid of selected patient.
//   $type  - type (medical_problem, allergy, medication, etc)
//   $value  - value searching for
//   $dateTarget - target date. blank is current date.
// Return: boolean if check passed, otherwise false
function exist_lists_item($patient_id,$type,$value,$dateTarget) {

  // Set date to current if not set
  $dateTarget = ($dateTarget) ? $dateTarget : date('Y-m-d H:i:s');

  // Attempt to explode the value into a code type and code (if applicable)
  $value_array = explode("::",$value);
  if (count($value_array) == 2) {

    // Collect the code type and code
    $code_type = $value_array[0];
    $code = $value_array[1];

    if ($code_type=='CUSTOM') {
      // Deal with custom code type first (title column in lists table)
      $response = sqlQuery("SELECT * FROM `lists` " .
        "WHERE `type`=? " .
        "AND `pid`=? " .
        "AND `title` LIKE ? " .
        "AND ( (`begdate` IS NULL AND `date`<=?) OR (`begdate` IS NOT NULL AND `begdate`<=?) ) " .
        "AND ( (`enddate` IS NULL) OR (`enddate` IS NOT NULL AND `enddate`>=?) )", array($type,$patient_id,"%".$code."%",$dateTarget,$dateTarget,$dateTarget) );
      if (!empty($response)) return true;
    }
    else {
      // Deal with the set code types (diagnosis column in lists table)
      $response = sqlQuery("SELECT * FROM `lists` " .
        "WHERE `type`=? " .
        "AND `pid`=? " .
        "AND `diagnosis` LIKE ? " .
        "AND ( (`begdate` IS NULL AND `date`<=?) OR (`begdate` IS NOT NULL AND `begdate`<=?) ) " .
        "AND ( (`enddate` IS NULL) OR (`enddate` IS NOT NULL AND `enddate`>=?) )", array($type,$patient_id,"%".$code_type.":".$code."%",$dateTarget,$dateTarget,$dateTarget) );
      if (!empty($response)) return true;
    }
  }
  else { // count($value_array) == 1
    // Search the title column in lists table
    //   Yes, this is essentially the same as the code type listed as CUSTOM above. This provides flexibility and will ensure compatibility.
    $response = sqlQuery("SELECT * FROM `lists` " .
      "WHERE `type`=? " .
      "AND `pid`=? " .
      "AND `title` LIKE ? ".
      "AND ( (`begdate` IS NULL AND `date`<=?) OR (`begdate` IS NOT NULL AND `begdate`<=?) ) " .
      "AND ( (`enddate` IS NULL) OR (`enddate` IS NOT NULL AND `enddate`>=?) )", array($type,$patient_id,"%".$value."%",$dateTarget,$dateTarget,$dateTarget) );
    if (!empty($response)) return true;
  }

  return false;
}

// Function to return part of sql query to deal with interval
// Parameters:
//   $table         - selected mysql table (or EXCEPTION(s))
//   $intervalType  - type of interval (ie. year)
//   $intervalValue - searched for within this many times of the interval type
//   $dateTarget    - target date.
// Return: string containing pertinent date interval filter for mysql query
function sql_interval_string($table,$intervalType,$intervalValue,$dateTarget) {

  $dateSql="";

  // Collect the correct column label for date in the table
  $date_label = collect_database_label('date',$table);

  // Deal with interval
  if (!empty($intervalType)) {
    switch($intervalType) {
      case "year":
        $dateSql = "AND (" . add_escape_custom($date_label) .
          " BETWEEN DATE_SUB('" . add_escape_custom($dateTarget) .
          "', INTERVAL " . add_escape_custom($intervalValue) .
          " YEAR) AND '" . add_escape_custom($dateTarget) . "') ";
        break;
      case "month":
        $dateSql = "AND (" . add_escape_custom($date_label) .
          " BETWEEN DATE_SUB('" . add_escape_custom($dateTarget) .
          "', INTERVAL " . add_escape_custom($intervalValue) .
          " MONTH) AND '" . add_escape_custom($dateTarget) . "') ";
        break;
      case "week":
        $dateSql = "AND (" . add_escape_custom($date_label) .
          " BETWEEN DATE_SUB('" . add_escape_custom($dateTarget) .
          "', INTERVAL " . add_escape_custom($intervalValue) .
          " WEEK) AND '" . add_escape_custom($dateTarget) . "') ";
        break;
      case "day":
        $dateSql = "AND (" . add_escape_custom($date_label) .
          " BETWEEN DATE_SUB('" . add_escape_custom($dateTarget) .
          "', INTERVAL " . add_escape_custom($intervalValue) .
          " DAY) AND '" . add_escape_custom($dateTarget) . "') ";
        break;
      case "hour":
        $dateSql = "AND (" . add_escape_custom($date_label) .
          " BETWEEN DATE_SUB('" . add_escape_custom($dateTarget) .
          "', INTERVAL " . add_escape_custom($intervalValue) .
          " HOUR) AND '" . add_escape_custom($dateTarget) . "') ";
        break;
      case "minute":
        $dateSql = "AND (" . add_escape_custom($date_label) .
          " BETWEEN DATE_SUB('" . add_escape_custom($dateTarget) .
          "', INTERVAL " . add_escape_custom($intervalValue) .
          " MINUTE) AND '" . add_escape_custom($dateTarget) . "') ";
        break;
      case "second":
        $dateSql = "AND (" . add_escape_custom($date_label) .
          " BETWEEN DATE_SUB('" . add_escape_custom($dateTarget) .
          "', INTERVAL " . add_escape_custom($intervalValue) .
          " SECOND) AND '" . add_escape_custom($dateTarget) . "') ";
        break;
      case "flu_season":
        // Flu season to be hard-coded as September thru February
        //  (Should make this modifiable in the future)
        //  ($intervalValue is not used)
        $dateArray = explode("-",$dateTarget);
        $Year = $dateArray[0];
        $dateThisYear = $Year . "-08-01";
        $dateLastYear = ($Year-1) . "-08-01";
        $dateSql =" " .
          "AND ((" .
              "MONTH('" . add_escape_custom($dateTarget) . "') < 8 " .
              "AND " . add_escape_custom($date_label) . " >= '" . $dateLastYear . "' ) " .
            "OR (" .
              "MONTH('" . add_escape_custom($dateTarget) . "') >= 8 " .
              "AND " . add_escape_custom($date_label) . " >= '" . $dateThisYear . "' ))" .
          "AND " . add_escape_custom($date_label) . " <= '" . add_escape_custom($dateTarget) . "' ";
        break;
    }
  }
  else {
    $dateSql = "AND " . add_escape_custom($date_label) .
      " <= '" . add_escape_custom($dateTarget)  . "' ";
  }

 // return the sql interval string
 return $dateSql;
}

// Function to collect generic column labels from tables.
//  It currently works for date and pid.
//  Will need to expand this as algorithm grows.
// Parameters:
//   $label - element (pid or date)
//   $table - selected mysql table (or EXCEPTION(s))
// Return: string containing official label of selected element
function collect_database_label($label,$table) {
 
  if ($table == 'PROCEDURE-EXCEPTION') {
    // return cell to get procedure collection
    // special case since reuqires joing of multiple
    // tables to get this value
    if ($label == "pid") {
      $returnedLabel = "procedure_order.patient_id";
    }
    else if ($label == "date") {
      $returnedLabel = "procedure_report.date_collected";
    }
    else {
      // unknown label, so return the original label
      $returnedLabel = $label;
    }
  }
  else if ($table == 'immunizations') {
    // return requested label for immunization table
    if ($label == "pid") {
      $returnedLabel = "patient_id";
    }
    else if ($label == "date") {
      $returnedLabel = "`administered_date`";
    }
    else {
      // unknown label, so return the original label
      $returnedLabel = $label;
    }
  }
  else {
    // return requested label for default tables
    if ($label == "pid") {
      $returnedLabel = "pid";
    }
    else if ($label == "date") {
      $returnedLabel = "`date`";
    }
    else {
      // unknown label, so return the original label
      $returnedLabel = $label;
    }
  }

  return $returnedLabel;
}

// Simple function to avoid processing of duplicate actions
// Parameters:
//   $actions - 2-dimensional array with all current active targets
//   $action  - array of selected target to test for duplicate
// Return: boolean, true if duplicate, false if not duplicate
function is_duplicate_action($actions,$action) {
  foreach ($actions as $row) {
    if ($row['category'] == $action['category'] &&
        $row['item'] == $action['item'] &&
        $row['value'] == $action['value']) {
      // Is a duplicate
      return true;
    }
  }

  // Not a duplicate
  return false;
}

// Calculate the reminder dates.
// Parameters:
//   $rule       - id(string) of selected rule
//   $dateTarget - target date. If blank then will test with current date as target.
//   $type       - either 'patient_reminder' or 'clinical_reminder'
// For now, will always return an array of 3 dates:
//   first date is before the target date (past_due) (default of 1 month)
//   second date is the target date (due)
//   third date is after the target date (soon_due) (default of 2 weeks)
function calculate_reminder_dates($rule, $dateTarget='',$type) {

  // Set date to current if not set
  $dateTarget = ($dateTarget) ? $dateTarget : date('Y-m-d H:i:s');

  // Collect the current date settings (to ensure not skip)
  $res = resolve_reminder_sql($rule, $type.'_current');
  if (!empty($res)) {
    $row = $res[0];
    if ($row ['method_detail'] == "SKIP") {
      $dateTarget = "SKIP";
    }
  }

  // Collect the past_due date
  $past_due_date = "";
  $res = resolve_reminder_sql($rule, $type.'_post');
  if (!empty($res)) {
    $row = $res[0];
    if ($row ['method_detail'] == "week") {
      $past_due_date = date("Y-m-d H:i:s", strtotime($dateTarget . " -" . $row ['value'] . " week"));
    }
    if ($row ['method_detail'] == "month") {
      $past_due_date = date("Y-m-d H:i:s", strtotime($dateTarget . " -" . $row ['value'] . " month"));
    }
    if ($row ['method_detail'] == "hour") {
      $past_due_date = date("Y-m-d H:i:s", strtotime($dateTarget . " -" . $row ['value'] . " hour"));
    }
    if ($row ['method_detail'] == "SKIP") {
      $past_due_date = "SKIP";
    }
  }
  else {
    // empty settings, so use default of one month
    $past_due_date = date("Y-m-d H:i:s", strtotime($dateTarget . " -1 month"));
  }

  // Collect the soon_due date
  $soon_due_date = "";
  $res = resolve_reminder_sql($rule, $type.'_pre');
  if (!empty($res)) {
    $row = $res[0];
    if ($row ['method_detail'] == "week") {
      $soon_due_date = date("Y-m-d H:i:s", strtotime($dateTarget . " +" . $row ['value'] . " week"));
    }
    if ($row ['method_detail'] == "month") {
      $soon_due_date = date("Y-m-d H:i:s", strtotime($dateTarget . " +" . $row ['value'] . " month"));
    }
    if ($row ['method_detail'] == "hour") {
      $soon_due_date = date("Y-m-d H:i:s", strtotime($dateTarget . " -" . $row ['value'] . " hour"));
    }
    if ($row ['method_detail'] == "SKIP") {
      $soon_due_date = "SKIP";
    }
  }
  else {
    // empty settings, so use default of one month
    $soon_due_date = date("Y-m-d H:i:s", strtotime($dateTarget . " +2 week"));
  }

  // Return the array of three dates
  return array($soon_due_date,$dateTarget,$past_due_date);
}

// Adds an action into the reminder array
// Parameters:
//   $reminderOldArray - Contains the current array of reminders
//   $reminderNew      - Array of a new reminder
// Return:
//   An array of reminders
function reminder_results_integrate($reminderOldArray, $reminderNew) {

  $results = array();

  // If reminderArray is empty, then insert new reminder
  if (empty($reminderOldArray)) {
    array_push($results, $reminderNew);
    return $results;
  }

  // If duplicate reminder, then replace the old one
  $duplicate = false;
  foreach ($reminderOldArray as $reminderOld) {
    if (  $reminderOld['pid'] == $reminderNew['pid'] &&
          $reminderOld['category'] == $reminderNew['category'] &&
          $reminderOld['item'] == $reminderNew['item']) {
      array_push($results, $reminderNew);
      $duplicate = true;
    }
    else {
      array_push($results, $reminderOld);
    }
  }

  // If a new reminder, then insert the new reminder
  if (!$duplicate) {
    array_push($results, $reminderNew);
  }

  return $results;
}

// Compares number of items with requested comparison operator
// Parameters:
//   $comp      - Comparison operator(eq,ne,gt,ge,lt,le)
//   $thres     - Threshold used in comparison
//   $num_items - Number of items
// Return:
//   Boolean of comparison results
function itemsNumberCompare($comp, $thres, $num_items) {

  if ( ($comp == "eq") && ($num_items == $thres) ) {
    return true;
  }
  else if ( ($comp == "ne") && ($num_items != $thres) && ($num_items > 0) ) {
    return true;
  }
  else if ( ($comp == "gt") && ($num_items > $thres) ) {
    return true;
  }
  else if ( ($comp == "ge") && ($num_items >= $thres) ) {
    return true;
  }
  else if ( ($comp == "lt") && ($num_items < $thres) && ($num_items > 0) ) {
    return true;
  }
  else if ( ($comp == "le") && ($num_items <= $thres) && ($num_items > 0) ) {
    return true;
  }
  else {
    return false;
  }
}

// Converts a text comparison operator to sql equivalent
// Parameters:
//   $comp      - Comparison operator(eq,ne,gt,ge,lt,le)
// Return:
//   String containing sql compatible comparison operator
function convertCompSql($comp) {

  if ($comp == "eq") {
    return "=";
  }
  else if ($comp == "ne") {
    return "!=";
  }
  else if ($comp == "gt") {
    return ">";
  }
  else if ($comp == "ge") {
    return ">=";
  }
  else if ($comp == "lt") {
    return "<";
  }
  else { // ($comp == "le")
    return "<=";
  }
}

// Function to find age in years (with decimal) on the target date
// Parameters:
//   $dob        - date of birth
//   $target     - date to calculate age on
// Return: decimal, years(decimal) from dob to target(date)
function convertDobtoAgeYearDecimal($dob,$target) { 
     
    // Prepare dob (Y M D)
    $dateDOB = explode(" ",$dob);

    // Prepare target (Y-M-D H:M:S)
    $dateTargetTemp = explode(" ",$target);
    $dateTarget = explode("-",$dateTargetTemp[0]);

    // Collect differences 
    $iDiffYear  = $dateTarget[0] - $dateDOB[0]; 
    $iDiffMonth = $dateTarget[1] - $dateDOB[1]; 
    $iDiffDay   = $dateTarget[2] - $dateDOB[2]; 
     
    // If birthday has not happen yet for this year, subtract 1. 
    if ($iDiffMonth < 0 || ($iDiffMonth == 0 && $iDiffDay < 0)) 
    { 
        $iDiffYear--; 
    } 

    // Ensure diffYear is not less than 0
    if ($iDiffYear < 0) $iDiffYear = 0;
     
    return $iDiffYear; 
}  

// Function to find age in months (with decimal) on the target date
// Parameters:
//   $dob        - date of birth
//   $target     - date to calculate age on
// Return: decimal, months(decimal) from dob to target(date)
function convertDobtoAgeMonthDecimal($dob,$target) {

    // Prepare dob (Y M D)
    $dateDOB = explode(" ",$dob);

    // Prepare target (Y-M-D H:M:S)
    $dateTargetTemp = explode(" ",$target);
    $dateTarget = explode("-",$dateTargetTemp[0]);

    // Collect differences
    $iDiffYear  = $dateTarget[0] - $dateDOB[0];
    $iDiffMonth = $dateTarget[1] - $dateDOB[1];
    $iDiffDay   = $dateTarget[2] - $dateDOB[2];

    // If birthday has not happen yet for this year, subtract 1.
    if ($iDiffMonth < 0 || ($iDiffMonth == 0 && $iDiffDay < 0))
    {
        $iDiffYear--;
    }

    // Ensure diffYear is not less than 0
    if ($iDiffYear < 0) $iDiffYear = 0;

    return (12 * $iDiffYear) + $iDiffMonth;
}

// Function to calculate the percentage
// Parameters:
//   $pass_filter    - number of patients that pass filter
//   $exclude_filter - number of patients that are excluded
//   $pass_target    - number of patients that pass target
// Return: String of a number formatted into a percentage
function calculate_percentage($pass_filt,$exclude_filt,$pass_targ) {
  if ($pass_filt > 0) {
    $perc = number_format(($pass_targ/($pass_filt-$exclude_filt))*100) . xl('%');
  }
  else {
    $perc = "0". xl('%');
  }
  return $perc;
}

