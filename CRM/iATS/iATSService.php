<?php
/* iATS Service Request Object used for accessing iATS Service Interface
 *
 * A lightweight object that encapsulates the details of the iATS Payments interface
 *
 * Provides SOAP interface details for the various methods,
 * error messages, and cc details
 *
 * Require the method id string on construction and any options like trace, logging.
 * Require the specific payment details, and the client credentials, on request
 *
 * TODO: provide logging options for the request, exception and response
 *
 * Expected usage:
 * $iats = new iATS_Service_Request($method_code, $options)
 * where: method code is 'cc', etc., options allows for logging options
 * $response = $iats->request($credentials,$payment)
 * the request method encapsulates the soap inteface and requires iATS client details + payment info (cc + amount + billing info)
 * $result = $iats->response($response)
 * the 'response' method converts the soap response into a nicer format
 **/

Class iATS_Service_Request {

  /* check iATS website for additional supported currencies */
  CONST CURRENCIES = 'CAD,USD,AUD,GBP,EUR,NZD';
  // iATS transaction mode definitions:
  CONST iATS_TXN_NS = 'xmlns';
  CONST iATS_TXN_TRACE = TRUE;
  CONST iATS_TXN_SUCCESS = 'Success';
  CONST iATS_TXN_OK = 'OK';
  CONST iATS_URL_PROCESSLINK = 'https://www.iatspayments.com/NetGate/ProcessLink.asmx?WSDL';
  CONST iATS_URL_REPORTLINK = 'https://www.iatspayments.com/NetGate/ReportLink.asmx?WSDL';
  CONST iATS_URL_CUSTOMERLINK = 'https://www.iatspayments.com/NetGate/CustomerLink.asmx?WSDL';

  function __construct($method, $options = array()) {
    $type = isset($options['type']) ? $options['type'] : 'process';
    switch($type) {
      case 'report':
        $this->_wsdl_url = self::iATS_URL_REPORTLINK;
        break;
      case 'customer':
        $this->_wsdl_url = self::iATS_URL_CUSTOMERLINK;
        break;
      case 'process':
      default:
        $this->_wsdl_url = self::iATS_URL_PROCESSLINK;
        break;
    }
    // TODO: check that the method is allowed!
    $this->method = $this->methodInfo($type,$method);
    // initialize the request array
    $this->request = array();
    // name space url
    $this->_wsdl_url_ns = 'https://www.iatspayments.com/NetGate/';
    // TODO: go through options and ensure defaults
    $this->options = $options;
    $this->options['log'] = array('all' => 1);
    $this->options['trace'] = 1;
  }

  /**
   * Submits an API request through the iATS SOAP API Toolkit.
   *
   * @param $request
   *   The request object or array containing the parameters of the requested services.
   *
   * @return
   *   The response object from the API with properties pertinent to the requested
   *     services.
   */
  function request($credentials, $payment) {
    // Attempt the SOAP request and log the exception on failure.
    $method = $this->method['method'];
    if (empty($method)) {
      dsm($this->method);
      return FALSE;
    }
    $message = $this->method['message'];
    $response = $this->method['response'];
    // always log requests, start by making a copy of the original request
    if (!empty($payment['invoiceNum'])) {
      $logged_request = $payment;
      // mask the cc numbers
      $this->mask($logged_request);
      // log: ip, invoiceNum, , cc, total, date
      // dpm($logged_request);
      $cc = isset($logged_request['creditCardNum']) ? $logged_request['creditCardNum'] :  (isset($logged_request['ccNum']) ? $logged_request['ccNum'] : '');
      $query_params = array(
        1 => array($logged_request['invoiceNum'], 'String'),
        2 => array($logged_request['customerIPAddress'], 'String'),
        3 => array(substr($cc, -4), 'String'),
        4 => array('', 'String'),
        5 => array($logged_request['total'], 'String'),
      );
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_iats_request_log
        (invoice_num, ip, cc, customer_code, total, request_datetime) VALUES (%1, %2, %3, %4, %5, NOW())", $query_params);
      // save the invoiceNum so I can log it for the response
      $this->invoiceNum = $logged_request['invoiceNum']; 
    }
    // the agent user and password only get put in here so they don't end up in a log above
    try {
      $soapClient = new SoapClient($this->_wsdl_url, array('trace' => $this->options['trace']));
      // watchdog('iats_civicrm_ca', 'Soap Client: !obj', array('!obj' => print_r($soapClient, TRUE)), WATCHDOG_NOTICE);
      /* build the request manually as per the iATS docs */
      $xml = '<'.$message.' xmlns="'.$this->_wsdl_url_ns.'">';
      // $request = array_merge($this->request,(array) $payment, (array) $credentials);
      $request = array_merge($this->request,(array) $credentials, (array) $payment);
      foreach($request as $k => $v) {
         $xml .= '<'.$k.'>'.$v.'</'.$k.'>';
      }
      $xml .= '</'.$message.'>';
      if (!empty($this->options['log']['all']) || !empty($this->options['log']['xml'])) {
         watchdog('iats_civicrm_ca', 'Method info: !method', array('!method' => $method), WATCHDOG_NOTICE);
         watchdog('iats_civicrm_ca', 'XML: !xml', array('!xml' => $xml), WATCHDOG_NOTICE);
      }
      $soapRequest = new SoapVar($xml, XSD_ANYXML);
      if (!empty($this->options['log']['all']) || !empty($this->options['log']['xml'])) {
         watchdog('iats_civicrm_ca', 'Request !request', array('!request' => print_r($soapRequest,TRUE)), WATCHDOG_NOTICE);
      }
      $soapResponse = $soapClient->$method($soapRequest);
      if (!empty($this->options['log']['all']) || !empty($this->options['log']['request'])) {
         $response_log = "\n HEADER:\n";
         $response_log .= $soapClient->__getLastResponseHeaders();
         $response_log .= "\n BODY:\n";
         $response_log .= $soapClient->__getLastResponse();
         $response_log .= "\n BODYEND:\n";
         watchdog('iats_civicrm_ca', 'Response: !response', array('!response' => '<pre>' . $response_log . '</pre>'), WATCHDOG_NOTICE);
      }
    }
    catch (SoapFault $exception) {
      if (!empty($this->options['log']['all']) || !empty($this->options['log']['exception'])) {
        watchdog('iats_civicrm_ca', 'SoapFault: !exception', array('!exception' => '<pre>' . print_r($exception, TRUE) . '</pre>'), WATCHDOG_ERROR);
        $response_log = "\n HEADER:\n";
        $response_log .= $soapClient->__getLastResponseHeaders();
        $response_log .= "\n BODY:\n";
        $response_log .= $soapClient->__getLastResponse();
        $response_log .= "\n BODYEND:\n";
        watchdog('iats_civicrm_ca', 'Raw Response: !response', array('!response' => '<pre>' . $response_log . '</pre>'), WATCHDOG_NOTICE);
      }
      return FALSE;
    }

    // Log the response if specified.
    if (!empty($this->options['log']['all']) || !empty($this->options['log']['response'])) {
      watchdog('iats_civicrm_ca', 'iATS SOAP response: !request', array('!request' => '<pre>' . print_r($soapResponse, TRUE) . '</pre>', WATCHDOG_DEBUG));
    }
    $xml_response = $soapResponse->$response->any;
    return new SimpleXMLElement($xml_response);
  }

  function file($response) {
    return base64_decode($response->FILE);
  }

  /*
  * Process the response to the request into a more friendly format in an array $result;
  * Log the result to an internal table while I'm at it, unless explicitly not requested
  */
 
  function result($response, $log = TRUE) {
    $processresult = $response->PROCESSRESULT;
    $auth_result = trim(current($processresult->AUTHORIZATIONRESULT));
    $result = array('auth_result' => $auth_result,
                    'remote_id' => current($processresult->TRANSACTIONID)
    );
    // If we didn't get an approval response code...
    // Note: do not use SUCCESS property, which just means iATS said "hello"
    $result['status'] = (substr($auth_result,0,2) == self::iATS_TXN_OK) ? 1 : 0;

    // If the payment failed, display an error and rebuild the form.
    if (!$result['status']) {
      $result['reasonMessage'] = $this->reasonMessage($auth_result);
      if ($auth_result == 'REJECT: 5') {
        //drupal_set_message('You may have interrupted an authorization in progress - please contact us to process/complete your order.', 'error');
      }
      else {
        //drupal_set_message('Please enter your information again or try a different card.', 'error');
      }
    }
    if ($log) {
      $query_params = array(
        1 => array($this->invoiceNum, 'String'),
        2 => array($result['auth_result'], 'String'),
        3 => array($result['remote_id'], 'String'),
      );
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_iats_response_log
        (invoice_num, auth_result, remote_id, response_datetime) VALUES (%1, %2, %3, NOW())", $query_params);
    }
    return $result;
  }

  /*
   * Provides the soap parameters for each of the ways to process payments at iATS Services
   * Parameters are: method, message and response, these are all soap object properties
   * Title and description provide a public information interface, not used internally
   */
  function methodInfo($type = '', $method = '') {
    $desc = 'Integrates the iATS SOAP webservice: ';
    switch($type) {
      default:
      case 'process':
        $methods = array(
          'cc' => array(
            'title' => 'Credit card',
            'description'=> $desc. 'ProcessCreditCardV1',
            'method' => 'ProcessCreditCard',
            'message' => 'ProcessCreditCardV1',
            'response' => 'ProcessCreditCardV1Result',
          ),
          'cc_create_customer_code' => array(
            'title' => 'Credit card, saved',
            'description' => $desc. 'CreateCustomerCodeAndProcessCreditCardV1',
            'method' => 'CreateCustomerCodeAndProcessCreditCard',
            'message' => 'CreateCustomerCodeAndProcessCreditCardV1',
            'response' => 'CreateCustomerCodeAndProcessCreditCardV1Result',
          ),
          'cc_with_customer_code' => array(
            'title' => 'Credit card using saved info',
            'description' => $desc. 'ProcessCreditCardWithCustomerCodeV1',
            'method' => 'ProcessCreditCardWithCustomerCode',
            'message' => 'ProcessCreditCardWithCustomerCodeV1',
            'response' => 'ProcessCreditCardWithCustomerCodeV1Result',
          ),
          'acheft' => array(
            'title' => 'ACH/EFT',
            'description' => $desc. 'ProcessACHEFTV1',
            'method' => 'ProcessACHEFT',
            'message' => 'ProcessACHEFTV1',
            'response' => 'ProcessACHEFTV1Result',
          ),
          'acheft_create_customer_code' => array(
            'title' => 'ACH/EFT, saved',
            'description' => $desc. 'CreateCustomerCodeAndProcessACHEFTV1',
            'method' => 'CreateCustomerCodeAndProcessACHEFT',
            'message' => 'CreateCustomerCodeAndProcessACHEFTV1',
            'response' => 'CreateCustomerCodeAndProcessACHEFTV1Result',
          ),
          'acheft_with_customer_code' => array(
            'title' => 'ACH/EFT with customer code',
            'description' => $desc. 'ProcessACHEFTWithCustomerCodeV1',
            'method' => 'ProcessACHEFTWithCustomerCode',
            'message' => 'ProcessACHEFTWithCustomerCodeV1',
            'response' => 'ProcessACHEFTWithCustomerCodeV1Result',
          ),
        );
        break;
      case 'report':
        $methods = array(
          'acheft_journal' => array(
            'title' => 'ACH-EFT Journal',
            'description'=> $desc. 'GetACHEFTJournalV1',
            'method' => 'GetACHEFTJournal',
            'message' => 'GetACHEFTJournalV1',
            'response' => 'GetACHEFTJournalV1Result',
          ),
          'acheft_journal_csv' => array(
            'title' => 'ACH-EFT Journal CSV',
            'description'=> $desc. 'GetACHEFTJournalCSVV1',
            'method' => 'GetACHEFTJournalCSV',
            'message' => 'GetACHEFTJournalCSVV1',
            'response' => 'GetACHEFTJournalCSVV1Result',
          ),
          'acheft_payment_box_journal_csv' => array(
            'title' => 'ACH-EFT Payment Box Journal CSV',
            'description'=> $desc. 'GetACHEFTPaymentBoxJournalCSV V1',
            'method' => 'GetACHEFTPaymentBoxJournalCSV',
            'message' => 'GetACHEFTPaymentBoxJournalCSV_x0020_V1',
            'response' => 'GetACHEFTPaymentBoxJournalCSV_x0020_V1Result',
          ),
          'acheft_payment_box_reject_csv' => array(
            'title' => 'ACH-EFT Payment Box Reject CSV',
            'description'=> $desc. 'GetACHEFTPaymentBoxRejectCSV V1',
            'method' => 'GetACHEFTPaymentBoxRejectCSV',
            'message' => 'GetACHEFTPaymentBoxRejectCSVV1',
            'response' => 'GetACHEFTPaymentBoxRejectCSVV1Result',
          ),
          'acheft_reject' => array(
            'title' => 'ACH-EFT Reject',
            'description'=> $desc. 'GetACHEFTRejectV1',
            'method' => 'GetACHEFTReject',
            'message' => 'GetACHEFTRejectV1',
            'response' => 'GetACHEFTRejectV1Result',
          ),
          'acheft_reject_csv' => array(
            'title' => 'ACH-EFT Reject CSV',
            'description'=> $desc. 'GetACHEFTRejectCSVV1',
            'method' => 'GetACHEFTRejectCSV',
            'message' => 'GetACHEFTRejectCSVV1',
            'response' => 'GetACHEFTRejectCSVV1Result',
          ),
        );
        break;
    }
    if ($method) {
      return $methods[$method];
    }
    return $methods;
  }


  /**
   * Returns the message text for a credit card service reason code.
   * As per iATS error codes - sent to us by Ryan Creamore
   * TODO: multilingual options?
   */
  function reasonMessage($code) {
    switch ($code) {

      case 'REJECT: 1':
         return 'Agent code has not been set up on the authorization system. Please call iATS at 1-888-955-5455.';
      case 'REJECT: 2':
         return 'Unable to process transaction. Verify and reenter credit card information.';
      case 'REJECT: 3':
         return 'Invalid customer code.';
      case 'REJECT: 4':
         return 'Incorrect expiry date.';
      case 'REJECT: 5':
         return 'Invalid transaction. Verify and re-enter credit card information.';
      case 'REJECT: 6':
         return 'Please have cardholder call the number on the back of the card.';
      case 'REJECT: 7':
         return 'Lost or stolen card.';
      case 'REJECT: 8':
         return 'Invalid card status.';
      case 'REJECT: 9':
         return 'Restricted card status, usually on corporate cards restricted to specific sales.';
      case 'REJECT: 10':
         return 'Error. Please verify and re-enter credit card information.';
      case 'REJECT: 11':
         return 'General decline code. Please have cardholder call the number on the back of the card.';
      case 'REJECT: 12':
         return 'Incorrect CVV2 or expiry date.';
      case 'REJECT: 14':
         return 'The card is over the limit.';
      case 'REJECT: 15':
         return 'General decline code. Please have cardholder call the number on the back of the card.';
      case 'REJECT: 16':
         return 'Invalid charge card number. Verify and re-enter credit card information.';
      case 'REJECT: 17':
         return 'Unable to authorize transaction. Authorizer needs more information for approval.';
      case 'REJECT: 18':
         return 'Card not supported by institution.';
      case 'REJECT: 19':
         return 'Incorrect CVV2 security code.';
      case 'REJECT: 22':
         return 'Bank timeout.  Bank lines may be down or busy. Retry later.';
      case 'REJECT: 23':
         return 'System error. Retry transaction later.';
      case 'REJECT: 24':
         return 'Charge card expired.';
      case 'REJECT: 25':
         return 'Capture card. Reported lost or stolen.';
      case 'REJECT: 26':
         return 'Invalid transaction, invalid expiry date. Please confirm and retry transaction.';
      case 'REJECT: 27':
         return 'Please have cardholder call the number on the back of the card.';
      case 'REJECT: 32':
         return 'Invalid charge card number.';
      case 'REJECT: 39':
         return 'Contact iATS at 1-888-955-5455.';
      case 'REJECT: 40':
         return 'Invalid card number. Card not supported by iATS.';
      case 'REJECT: 41':
         return 'Invalid expiry date.';
      case 'REJECT: 42':
         return 'CVV2 required.';
      case 'REJECT: 43':
         return 'Incorrect AVS.';
      case 'REJECT: 45':
        return 'Credit card name blocked. Call iATS at 1-888-955-5455.';
      case 'REJECT: 46':
        return 'Card tumbling. Call iATS at 1-888-955-5455.';
      case 'REJECT: 47':
        return 'Name tumbling. Call iATS at 1-888-955-5455.';
      case 'REJECT: 48':
        return 'IP blocked. Call iATS at 1-888-955-5455.';
      case 'REJECT: 49':
        return 'Velocity 1 – IP block. Call iATS at 1-888-955-5455.';
      case 'REJECT: 50':
        return 'Velocity 2 – IP block. Call iATS at 1-888-955-5455.';
      case 'REJECT: 51':
        return 'Velocity 3 – IP block. Call iATS at 1-888-955-5455.';
      case 'REJECT: 52':
        return 'Credit card BIN country blocked. Call iATS at 1-888-955-5455.';
      case 'REJECT: 100':
         return 'DO NOT REPROCESS. Call iATS at 1-888-955-5455.';
      case 'Timeout':
         return 'The system has not responded in the time allotted. Call iATS at 1-888-955-5455.';
    }

    return $code;
  }

  /**
   * Returns the message text for a CVV match.
   * This function not currently in use
   */
  function cvnResponse($code) {
    switch ($code) {
      case 'D':
        return t('The transaction was determined to be suspicious by the issuing bank.');
      case 'I':
        return t("The CVN failed the processor's data validation check.");
      case 'M':
        return t('The CVN matched.');
      case 'N':
        return t('The CVN did not match.');
      case 'P':
        return t('The CVN was not processed by the processor for an unspecified reason.');
      case 'S':
        return t('The CVN is on the card but was not included in the request.');
      case 'U':
        return t('Card verification is not supported by the issuing bank.');
      case 'X':
        return t('Card verification is not supported by the card association.');
      case '1':
        return t('Card verification is not supported for this processor or card type.');
      case '2':
        return t('An unrecognized result code was returned by the processor for the card verification response.');
      case '3':
        return t('No result code was returned by the processor.');
    }

    return '-';
  }

  function creditCardTypes() {
    return array(
      'VI' => t('Visa'),
      'MC' => t('MasterCard'),
      'AMX' => t('American Express'),
      'DISC' => t('Discover Card'),
    );
  }

  function mask(&$log_request) {
    // Mask the credit card number and CVV.
    foreach(array('creditCardNum','cvv2','ccNum') as $mask) {
      if (!empty($log_request[$mask])) {
        if (4 < strlen($log_request[$mask])) { // show the last four digits of cc numbers
          $log_request[$mask] = str_repeat('X', strlen($log_request[$mask]) - 4) . substr($log_request[$mask], -4);
        }
        else {
          $log_request[$mask] = str_repeat('X', strlen($log_request[$mask]));
        }
      }
    }
  }

  function credentials($payment_processor_id) {
    static $credentials = array();
    if (empty($credentials[$payment_processor_id])) {
      $select = 'SELECT user_name, password FROM civicrm_payment_processor WHERE id = %1';
      $args = array(
        1 => array($payment_processor_id, 'Int'),
      );
      $dao = CRM_Core_DAO::executeQuery($select,$args);
      if ($dao->fetch()) {
        $cred = array(
          'agentCode' => $dao->user_name,
          'password' => $dao->password,
        );
        $credentials[$payment_processor_id] = $cred;
        return $cred;
      }
      return;
    }
    return $credentials[$payment_processor_id];
  }
}
