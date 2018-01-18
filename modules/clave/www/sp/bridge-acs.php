<?php

/**
 * Assertion consumer service handler for clave bridge SP
 *
 */




//Hosted IdP config
$claveConfig = sspmod_clave_Tools::getMetadataSet("__DYNAMIC:1__","clave-idp-hosted");
SimpleSAML_Logger::debug('Clave Idp hosted metadata: '.print_r($claveConfig,true));

//Hosted SP config
$hostedSP = $claveConfig->getString('hostedSP', NULL);
if($hostedSP == NULL)
    throw new SimpleSAML_Error_Exception("No clave hosted SP configuration defined in clave bridge configuration.");
$claveSP = sspmod_clave_Tools::getMetadataSet($hostedSP,"clave-sp-hosted");
SimpleSAML_Logger::debug('Clave SP hosted metadata: '.print_r($claveSP,true));


//Get the mode for the SP
$SPdialect    = $claveSP->getString('dialect');
$SPsubdialect = $claveSP->getString('subdialect');

$expectedResponsePostParams = $claveConfig->getArray('sp.post.allowed', array());


//Which clave IdP to use
$idpEntityId = $claveConfig->getString('claveIdP', NULL);
if($idpEntityId == NULL)
    throw new SimpleSAML_Error_Exception("No clave IdP configuration defined in clave bridge configuration.");
$idpMetadata = sspmod_clave_Tools::getMetadataSet($idpEntityId,"clave-idp-remote");


$idpData['cert'] = $idpMetadata->getString('certData', NULL);

    
//Since issuer is dynamic when authenticated through stork (STORK-XX,
//XX being a country code) we simply ignore the issuer
$expectedIssuers = NULL;


$hiCertPath = $claveConfig->getString('certificate', NULL);
$hiKeyPath  = $claveConfig->getString('privatekey', NULL);

if($hiCertPath == NULL || $hiKeyPath == NULL)
    throw new SimpleSAML_Error_Exception("No clave SSO certificate or key defined for the IdP interface in clave bridge configuration.");


if(!isset($_REQUEST['SAMLResponse']))
   	throw new SimpleSAML_Error_BadRequest('No SAMLResponse POST param received.');

$resp = base64_decode($_REQUEST['SAMLResponse']);
SimpleSAML_Logger::debug("Received response: ".$resp);


//Get allowed post params to be forwarded to the SP
$forwardedParams = array();
foreach ($_POST as $name => $value){
    if(in_array($name,$expectedResponsePostParams))
        $forwardedParams[$name] = $value;
}







$clave = new sspmod_clave_SPlib();


if ($SPdialect === 'eidas')
    $clave->setEidasMode();


$id = $clave->getInResponseToFromReq($resp);


$state = SimpleSAML_Auth_State::loadState($id, 'clave:bridge:req');
SimpleSAML_Logger::debug('State on acs:'.print_r($state,true));
$reqData = $state['sp:request'];


// TODO
//Get Remote SP metadata
$spEntityId = $reqData['issuer'];
$spMetadata = sspmod_clave_Tools::getSPMetadata($claveConfig,$spEntityId);
SimpleSAML_Logger::debug('Clave SP remote metadata ('.$spEntityId.'): '.print_r($spMetadata,true));



//Get the mode for the IdP (remote SP specific or hosted IdP default)   // TODO ******
$IdPdialect    = $spMetadata->getString('dialect',
                                        $claveConfig->getString('dialect'));
$IdPsubdialect = $spMetadata->getString('subdialect',
                                        $claveConfig->getString('subdialect'));



SimpleSAML_Logger::debug("Certificate in source: ".$idpData['cert']);
$clave->addTrustedCert($idpData['cert']);


$clave->setValidationContext($id,
                             $state['bridge:returnPage'],
                             $expectedIssuers,
                             $state['bridge:mandatoryAttrs']);


//Expect encrypted assertions and try to decrypt them with the SP
//private key, also, don't ignore any existing plain assertion (this
//may change in the future)
$spkeypem  = sspmod_clave_Tools::readCertKeyFile($claveSP->getString('privatekey', NULL));
$expectEncrypted = $claveSP->getBoolean('assertions.encrypted', true);
$onlyEncrypted   = $claveSP->getBoolean('assertions.encrypted.only', false);

$clave->setDecipherParams($spkeypem,$expectEncrypted,$onlyEncrypted);
// TODO

$clave->validateStorkResponse($resp);




//We extract the status info
$statusInfo = "";
if($clave->isSuccess($statusInfo)){
    SimpleSAML_Logger::info("Authentication Successful");

    //Log for statistics: received successful Response from remote clave IdP
    $statsData = array(
        'spEntityID' =>  $claveSP->getString('entityid', ''),
        'idpEntityID' => $clave->getRespIssuer(),
        'protocol' => 'saml2-clave',
    );
    if (isset($state['saml:AuthnRequestReceivedAt'])) {
        $statsData['logintime'] = microtime(TRUE) - $state['saml:AuthnRequestReceivedAt'];
    }
    SimpleSAML_Stats::log('clave:sp:Response', $statsData);
    
}else{
    SimpleSAML_Logger::info("Authentication Error");
    
    //Log for statistics: received failed Response from remote clave IdP
    $status = array(
        'Code' => $statusInfo['MainStatusCode'],
        'SubCode' => $statusInfo['SecondaryStatusCode'],
        'Message' => $statusInfo['StatusMessage'],
    );

    $statsData = array(
        'spEntityID' => $claveSP->getString('entityid', ''),
        'idpEntityID' => $clave->getRespIssuer(),
        'protocol' => 'saml2-clave',
        'error' => $status,
    );

    if (isset($state['saml:AuthnRequestReceivedAt'])) {
        $statsData['logintime'] = microtime(TRUE) - $state['saml:AuthnRequestReceivedAt'];
    }

    SimpleSAML_Stats::log('clave:sp:Response:error', $statsData);
}


//For some reason, Clave may not return a main status code. In that case, we set responder error
if($statusInfo['MainStatusCode'] == sspmod_clave_SPlib::ATST_NOTAVAIL){
    $statusInfo['MainStatusCode'] = sspmod_clave_SPlib::ST_RESPONDER;
}







//We build a status response with the status codes returned by Clave
$status = $clave->generateStatus($statusInfo);

//We clone the assertions on the repsonse, as they are signed on source (signature kept for legal reasons).
$assertions = $clave->getRawAssertions();


//Generate response with attributes, show the response and send back with submit button







//******Hybrid STORK, eIDAS, own brew behaviour to get the ACS

//if ($SPdialect === 'stork')
//    $acs  = $reqData['assertionConsumerService'];  // TODO eIDAS
//if ($SPdialect === 'eidas')
//    $acs = $spMetadata->getArray('AssertionConsumerService',array(['Location' => ""]))[0]['Location'];  // TODO revisar que funciona  // TODO a veces FALLA. creo que porque el SP no lo tiene

//Try to get the ACS from the request
$acs='';  // TODO eIDAS
if(array_key_exists('assertionConsumerService',$reqData))
    $acs  = $reqData['assertionConsumerService'];
//If none, get it from the remote SP metadata
if($acs === NULL || $acs === '')
    $acs = $spMetadata->getArray('AssertionConsumerService',array(['Location' => ""]))[0]['Location'];


if($acs === NULL || $acs == "")
    throw new SimpleSAML_Error_Exception("No assertion consumer url found on the request or metadata for the remote SP: $spEntityId.");





$storkResp = new sspmod_clave_SPlib();


if ($IdPdialect === 'eidas')  // TODO eIDAS // TODO ***** 
    $storkResp->setEidasMode();




$hikeypem  = sspmod_clave_Tools::readCertKeyFile($hiKeyPath);
$hicertpem = sspmod_clave_Tools::readCertKeyFile($hiCertPath);


$storkResp->setSignatureKeyParams($hicertpem, $hikeypem, sspmod_clave_SPlib::RSA_SHA256);

$storkResp->setSignatureParams(sspmod_clave_SPlib::SHA256,sspmod_clave_SPlib::EXC_C14N);

// TODO
//Get response encryption config (remote SP configuration prioritary over hosted IdP config)
$encryptAssertions = $spMetadata->getBoolean('assertion.encryption',
                                             $claveConfig->getBoolean('assertion.encryption', false));

SimpleSAML_Logger::debug('Will encrypt assertions: '.$encryptAssertions);

$encryptAlgorithm  = $spMetadata->getString('assertion.encryption.keyAlgorithm',
                                            $claveConfig->getString('assertion.encryption.keyAlgorithm',
                                                                    sspmod_clave_SPlib::AES256_CBC));
// TODO read the overriding SP values
$storkResp->setCipherParams($reqData['spCert'],$encryptAssertions,$encryptAlgorithm);

$storkResp->setResponseParameters($storkResp::CNS_OBT,
                                  $acs,
                                  $reqData['id'],
                                  $claveConfig->getString('issuer', 'NOT_SET')
                                  );

$resp = $storkResp->generateStorkResponse($status,$assertions);



//Log for statistics: sentResponse to remote clave SP
$status = array(
    'Code' => $statusInfo['MainStatusCode'],
    'SubCode' => $statusInfo['SecondaryStatusCode'],
    'Message' => $statusInfo['StatusMessage'],
);

$statsData = array(
    'spEntityID' => $spEntityId,
    'idpEntityID' => $claveConfig->getString('issuer', 'NOT_SET'),
    'protocol' => 'saml2-clave',
    'status' => $status,
);

if (isset($state['saml:AuthnRequestReceivedAt'])) {
    $statsData['logintime'] = microtime(TRUE) - $state['saml:AuthnRequestReceivedAt'];
}

SimpleSAML_Stats::log('clave:idp:Response', $statsData);




//Redirecting to Clave IdP (Only HTTP-POST binding supported)
$post = array(
    'SAMLResponse'  => base64_encode($resp),
) + $forwardedParams;
SimpleSAML_Utilities::postRedirect($acs, $post);
