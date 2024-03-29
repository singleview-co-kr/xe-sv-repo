<?php
define('INIPAY_HOME', _XE_PATH_.'files/svpg/iniescrow');
define('INIPAY_LOGDIR', _XE_PATH_.'files/svpg/iniescrow/log');
define('INIPAY_KEYDIR', _XE_PATH_.'files/svpg/iniescrow/key');

class iniescrow extends SvpgPlugin 
{
	var $plugin_info;

	function pluginInstall($args) 
	{
		// mkdir
		FileHandler::makeDir(sprintf(_XE_PATH_."files/svpg/iniescrow/%s/key",$args->plugin_srl));
		FileHandler::makeDir(sprintf(_XE_PATH_."files/svpg/iniescrow/%s/log",$args->plugin_srl));
		// copy files
		FileHandler::copyFile(_XE_PATH_.'modules/svpg/plugins/iniescrow/.htaccess',sprintf(_XE_PATH_."files/svpg/%s/.htaccess",$args->plugin_srl));
		FileHandler::copyFile(_XE_PATH_.'modules/svpg/plugins/iniescrow/readme.txt',sprintf(_XE_PATH_."files/svpg/%s/readme.txt",$args->plugin_srl));
		FileHandler::copyFile(_XE_PATH_.'modules/svpg/plugins/iniescrow/key/pgcert.pem',sprintf(_XE_PATH_."files/svpg/%s/key/pgcert.pem",$args->plugin_srl));
	}

	function iniescrow() 
	{
		parent::SvpgPlugin();
	}

	function init(&$args)
	{
		$this->plugin_info = new StdClass();
		foreach ($args as $key=>$val)
		{
			$this->plugin_info->{$key} = $val;
		}
		foreach ($args->extra_var as $key=>$val)
		{
			$this->plugin_info->{$key} = $val->value;
		}
		Context::set('plugin_info', $this->plugin_info);
	}

	/**
	 * item_name
	 * price
	 * purchaser_name
	 * purchaser_email
	 * purchaser_telnum
	 */
	function getFormData($args)
	{
		if (!$args->price) return new BaseObject(0,'No input of price');
		if (!$args->svpg_module_srl) return new BaseObject(-1,'No input of svpg_module_srl');
		if (!$args->module_srl) return new BaseObject(-1,'No input of module_srl');

		Context::set('module_srl', $args->module_srl);
		Context::set('svpg_module_srl', $args->svpg_module_srl);
		Context::set('plugin_srl', $this->plugin_info->plugin_srl);

		Context::set('item_name', $args->item_name);
		Context::set('purchaser_name', $args->purchaser_name);
		Context::set('purchaser_email', $args->purchaser_email);
		Context::set('purchaser_telnum', $args->purchaser_telnum);
		Context::set('script_call_before_submit', $args->script_call_before_submit);
		Context::set('join_form', $args->join_form);

		$oTemplate = &TemplateHandler::getInstance();
		$tpl_path = _XE_PATH_."modules/svpg/plugins/iniescrow/tpl";
		$tpl_file = 'formdata.html';
		$form_data = $oTemplate->compile($tpl_path, $tpl_file);

		$output = new BaseObject();
		$output->data = $form_data;
		return $output;
	}

	function processReview($args)
	{
		$inipayhome = sprintf(_XE_PATH_."files/svpg/%s", $args->plugin_srl);

		require("libs/INILib.php");
		$inipay = new INIpay50;
		$inipay->SetField("inipayhome", $inipayhome);
		$inipay->SetField("type", "chkfake");
		$inipay->SetField("debug", "true");
		$inipay->SetField("enctype","asym");
		$inipay->SetField("admin", $this->plugin_info->inicis_pass);
		$inipay->SetField("checkopt", "false");
		$inipay->SetField("mid", $this->plugin_info->inicis_id);
		$inipay->SetField("price", $args->price);
		$inipay->SetField("nointerest", "no");
		$inipay->SetField("quotabase", iconv('UTF-8', 'EUC-KR', '선택:일시불:2개월:3개월:6개월'));

		/* 암호화 대상/값을 암호화 */
		$inipay->startAction();

		/* 암호화 결과 */
		if( $inipay->GetResult("ResultCode") != "00" ) {
			$resultMsg = iconv("EUC-KR", "UTF-8", $inipay->GetResult("ResultMsg"));
			return new BaseObject(-1, $resultMsg);
		}

		/* 세션정보 저장 */
		$_SESSION['INI_MID'] = $this->plugin_info->inicis_id;	//상점ID
		$_SESSION['INI_ADMIN'] = $this->plugin_info->inicis_pass;	// 키패스워드(키발급시 생성, 상점관리자 패스워드와 상관없음)
		$_SESSION['INI_PRICE'] = $args->price;   //가격 
		$_SESSION['INI_RN'] = $inipay->GetResult("rn"); //고정 (절대 수정 불가)
		$_SESSION['INI_ENCTYPE'] = $inipay->GetResult("enctype"); //고정 (절대 수정 불가)

		Context::set('encfield', $inipay->GetResult('encfield'));
		Context::set('certid', $inipay->GetResult('certid'));
		Context::set('inicis_id', $this->plugin_info->inicis_id);
		
		//if( $args->delivfee_inadvance == 'N' )
		//	$args->price -= $args->delivery_fee;

		Context::set('price', $args->price);
		Context::set('order_srl', $args->order_srl);
		$oTemplate = &TemplateHandler::getInstance();
		$tpl_path = _XE_PATH_."modules/svpg/plugins/iniescrow/tpl";
		$tpl_file = 'review.html';
		$tpl_data = $oTemplate->compile($tpl_path, $tpl_file);

		$output = new BaseObject();
		$output->add('tpl_data', $tpl_data);
		return $output;
	}

	function processPayment($args)
	{
		$inipayhome = sprintf(_XE_PATH_."files/svpg/%s", $args->plugin_srl);

		$vars = Context::getRequestVars();
		extract(get_object_vars($vars));

		$goodname = Context::get('goodname');
		$currency = Context::get('currency');

		require("libs/INILib.php");
		$inipay = new INIpay50;
		$inipay->SetField("inipayhome", $inipayhome);
		$inipay->SetField("type", "securepay");
		$inipay->SetField("pgid", "INIphp".$pgid); // $pgid is global var which defined in INICls.php
		$inipay->SetField("subpgip","203.238.3.10");
		$inipay->SetField("admin", $_SESSION['INI_ADMIN']);
		$inipay->SetField("debug", "true");
		$inipay->SetField("uid", $uid);
		$inipay->SetField("goodname", iconv("UTF-8", "EUC-KR", $goodname));
		$inipay->SetField("currency", $currency);

		$inipay->SetField("mid", $_SESSION['INI_MID']);
		$inipay->SetField("rn", $_SESSION['INI_RN']);
		$inipay->SetField("price", $_SESSION['INI_PRICE']);
		$inipay->SetField("enctype", $_SESSION['INI_ENCTYPE']);

		$inipay->SetField("buyername", iconv("UTF-8", "EUC-KR", Context::get('buyername')));
		$inipay->SetField("buyertel", Context::get('buyertel'));
		$inipay->SetField("buyeremail", Context::get('buyeremail'));
		$inipay->SetField("paymethod", Context::get('paymethod'));
		$inipay->SetField("encrypted", Context::get('encrypted'));
		$inipay->SetField("sessionkey", Context::get('sessionkey'));
		$inipay->SetField("url", 'singleview.co.kr');
		$inipay->SetField("cardcode", Context::get('cardcode'));

		$inipay->SetField("parentemail", Context::get('parentemail'));
		$inipay->SetField("recvname", Context::get('recvname'));
		$inipay->SetField("recvtel", Context::get('recvtel'));
		$inipay->SetField("recvaddr", Context::get('recvaddr'));
		$inipay->SetField("recvpostnum", Context::get('recvpostnum'));
		$inipay->SetField("recvmsg", Context::get('recvmsg'));
		$inipay->SetField("joincard", Context::get('joincard'));
		$inipay->SetField("joinexpire", Context::get('joinexpire'));
		$inipay->SetField("id_customer", Context::get('id_customer'));

		/* 지불요청 */
		$inipay->startAction();

		$utf8ResultMsg = iconv('EUC-KR', 'UTF-8', $inipay->GetResult('ResultMsg'));
		$utf8VACTName = iconv('EUC-KR', 'UTF-8', $inipay->GetResult('VACT_Name'));
		$utf8VACTInputName = iconv('EUC-KR', 'UTF-8', $inipay->GetResult('VACT_InputName'));

		// error check
		if ($inipay->GetResult('ResultCode') != '00') 
		{
			$output = new BaseObject(-1, $utf8ResultMsg);
			$output->add('state', '3'); // failure
		}
		else
		{
			$output = new BaseObject(0, $utf8ResultMsg);
			if ($this->getPaymethod(Context::get('paymethod'))=='VA')
			{
				$output->add('state', '1'); // not completed
			} else {
				$output->add('state', '2'); // completed (success)
			}
		}

		$output->add('payment_method', $this->getPaymethod(Context::get('paymethod')));
		$output->add('payment_amount', $_SESSION['INI_PRICE']);
		$output->add('result_code', $inipay->GetResult('ResultCode'));
		$output->add('result_message', $utf8ResultMsg);
		$output->add('vact_num', $inipay->GetResult('VACT_Num')); // 계좌번호
		$output->add('vact_bankname', $this->getBankName($inipay->GetResult('VACT_BankCode'))); //은행코드
		$output->add('vact_bankcode', $inipay->GetResult('VACT_BankCode')); //은행코드
		$output->add('vact_name', $utf8VACTName); // 예금주
		$output->add('vact_inputname', $utf8VACTInputName); // 송금자
		$output->add('vact_regnum', $inipay->GetResult('VACT_RegNum')); //송금자 주번
		$output->add('vact_date', $inipay->GetResult('VACT_Date')); // 송금일자
		$output->add('vact_time', $inipay->GetResult('VACT_Time')); // 송금시간
		$output->add('pg_tid', $inipay->GetResult('TID'));
		return $output;
	}

	function processReport(&$transaction)
	{
		$inipayhome = sprintf(_XE_PATH_."files/svpg/%s", $transaction->plugin_srl);
		$TEMP_IP = $_SERVER["REMOTE_ADDR"];
		$PG_IP  = substr($TEMP_IP,0, 10);

		//PG에서 보냈는지 IP로 체크
		if( $PG_IP != "203.238.37" && $PG_IP != "210.98.138" )  {
			return new BaseObject(-1, 'msg_invalid_request');
		}
		/*
		$msg_id = $msg_id;             //메세지 타입
		$no_tid = $no_tid;             //거래번호
		$no_oid = $no_oid;             //상점 주문번호
		$id_merchant = $id_merchant;   //상점 아이디
		$cd_bank = $cd_bank;           //거래 발생 기관 코드
		$cd_deal = $cd_deal;           //취급 기관 코드
		$dt_trans = $dt_trans;         //거래 일자
		$tm_trans = $tm_trans;         //거래 시간
		$no_msgseq = $no_msgseq;       //전문 일련 번호
		$cd_joinorg = $cd_joinorg;     //제휴 기관 코드

		$dt_transbase = $dt_transbase; //거래 기준 일자
		$no_transeq = $no_transeq;     //거래 일련 번호
		$type_msg = $type_msg;         //거래 구분 코드
		$cl_close = $cl_close;         //마감 구분코드
		$cl_kor = $cl_kor;             //한글 구분 코드
		$no_msgmanage = $no_msgmanage; //전문 관리 번호
		$no_vacct = $no_vacct;         //가상계좌번호
		$amt_input = $amt_input;       //입금금액
		$amt_check = $amt_check;       //미결제 타점권 금액
		$nm_inputbank = $nm_inputbank; //입금 금융기관명
		$nm_input = $nm_input;         //입금 의뢰인
		$dt_inputstd = $dt_inputstd;   //입금 기준 일자
		$dt_calculstd = $dt_calculstd; //정산 기준 일자
		$flg_close = $flg_close;       //마감 전화
		*/
		/*
		//가상계좌채번시 현금영수증 자동발급신청시에만 전달
		$dt_cshr      = $dt_cshr;       //현금영수증 발급일자
		$tm_cshr      = $tm_cshr;       //현금영수증 발급시간
		$no_cshr_appl = $no_cshr_appl;  //현금영수증 발급번호
		$no_cshr_tid  = $no_cshr_tid;   //현금영수증 발급TID
		*/
		$logfile = fopen($inipayhome."/log/vbank_" . date("Ymd") . ".log", "a+");
		$vars = Context::getRequestVars();
		foreach ($vars as $key=>$val) {
			fwrite( $logfile,$key." : ".$val."\n");
		}
		/*
		$output = $this->processPayment(Context::get('no_oid'), Context::get('amt_input'));
		if (!$output->toBool()) return $output;
		*/
		fwrite( $logfile,"************************************************\n\n");
		fclose( $logfile );

		//위에서 상점 데이터베이스에 등록 성공유무에 따라서 성공시에는 "OK"를 이니시스로
		//리턴하셔야합니다. 아래 조건에 데이터베이스 성공시 받는 FLAG 변수를 넣으세요
		//(주의) OK를 리턴하지 않으시면 이니시스 지불 서버는 "OK"를 수신할때까지 계속 재전송을 시도합니다
		//기타 다른 형태의 PRINT( echo )는 하지 않으시기 바랍니다
		$output = new BaseObject();
		$output->order_srl = Context::get('no_oid');
		$output->amount = Context::get('amt_input');
		if ($output->amount == $transaction->payment_amount)
		{
			//echo "OK";
			$output->setError(0); // successfully completed
			$output->setMessage('SVPG_RC'); // receive_confirmed
			//$output->state = '2'; // successfully completed
		}
		else
		{
			//$output->setError(-1);
			//$output->setMessage('amount not match');
			//$output->state = '1'; // not completed
			$output->setError(-1); // not completed
			$output->setMessage('SVPG_AME'); // amount_mismatch_error
		}
		return $output;
	}

	function getReceipt($pg_tid, $paymethod = NULL)
	{
		Context::set('tid', $pg_tid);
		$oTemplate = &TemplateHandler::getInstance();
		$tpl_path = _XE_PATH_."modules/svpg/plugins/iniescrow/tpl";
		$tpl_file = 'receipt.html';
		$tpl = $oTemplate->compile($tpl_path, $tpl_file);
		return $tpl;
	}

	function getReport() 
	{
		$output = new BaseObject();
		$output->order_srl = Context::get('no_oid');
		$output->amount = Context::get('amt_input');
		return $output;
	}

	function dispEscrowDelivery(&$order_info, &$payment_info, &$escrow_info)
	{
		Context::set('order_info', $order_info);
		Context::set('payment_info', $payment_info);
		Context::set('escrow_info', $escrow_info);
		$oTemplate = &TemplateHandler::getInstance();
		$tpl_path = _XE_PATH_."modules/svpg/plugins/iniescrow/tpl";
		$tpl_file = 'escrow_delivery.html';
		return $oTemplate->compile($tpl_path, $tpl_file);
	}

	function dispEscrowConfirm(&$order_info, &$payment_info, &$escrow_info)
	{
		Context::set('order_info', $order_info);
		Context::set('payment_info', $payment_info);
		Context::set('escrow_info', $escrow_info);
		$oTemplate = &TemplateHandler::getInstance();
		$tpl_path = _XE_PATH_."modules/svpg/plugins/iniescrow/tpl";
		$tpl_file = 'escrow_confirm.html';
		return $oTemplate->compile($tpl_path, $tpl_file);
	}

	function dispEscrowDenyConfirm(&$order_info, &$payment_info, &$escrow_info)
	{
		Context::set('order_info', $order_info);
		Context::set('payment_info', $payment_info);
		Context::set('escrow_info', $escrow_info);
		$oTemplate = &TemplateHandler::getInstance();
		$tpl_path = _XE_PATH_."modules/svpg/plugins/iniescrow/tpl";
		$tpl_file = 'escrow_denyconfirm.html';
		return $oTemplate->compile($tpl_path, $tpl_file);
	}


	function procEscrowDelivery()
	{
		$inipayhome = sprintf(_XE_PATH_."files/svpg/%s", $this->plugin_info->plugin_srl);

		$vars = Context::getRequestVars();
		debugPrint($vars);
		extract(get_object_vars($vars));

		require("libs/INILib.php");
		$iniescrow = new INIpay50;
		$iniescrow->SetField("inipayhome", $inipayhome);
		$iniescrow->SetField("tid", Context::get('tid'));
		$iniescrow->SetField("mid", $this->plugin_info->inicis_id);
		$iniescrow->SetField("admin", $this->plugin_info->inicis_pass);
		$iniescrow->SetField("type", "escrow");
		$iniescrow->SetField("escrowtype", "dlv");
		$iniescrow->SetField("dlv_ip", getenv("REMOTE_ADDR"));
		$iniescrow->SetField("debug", "true");

		$iniescrow->SetField("oid",$oid);
		$iniescrow->SetField("soid","1");
		$iniescrow->SetField("dlv_date",$dlv_date);
		$iniescrow->SetField("dlv_time",$dlv_time);
		$iniescrow->SetField("dlv_report",$EscrowType);
		$iniescrow->SetField("dlv_invoice",$invoice);
		$iniescrow->SetField("dlv_name",$dlv_name);
		
		$iniescrow->SetField("dlv_excode",$dlv_exCode);
		$iniescrow->SetField("dlv_exname",$dlv_exName);
		$iniescrow->SetField("dlv_charge",$dlv_charge);
		
		$iniescrow->SetField("dlv_invoiceday",$dlv_invoiceday);
		$iniescrow->SetField("dlv_sendname",$sendName);
		$iniescrow->SetField("dlv_sendpost",$sendPost);
		$iniescrow->SetField("dlv_sendaddr1",$sendAddr1);
		$iniescrow->SetField("dlv_sendaddr2",$sendAddr2);
		$iniescrow->SetField("dlv_sendtel",$sendTel);

		$iniescrow->SetField("dlv_recvname",$recvName);
		$iniescrow->SetField("dlv_recvpost",$recvPost);
		$iniescrow->SetField("dlv_recvaddr",$recvAddr);
		$iniescrow->SetField("dlv_recvtel",$recvTel);
		
		$iniescrow->SetField("dlv_goodscode",$goodsCode);
		$iniescrow->SetField("dlv_goods",$goods);
		$iniescrow->SetField("dlv_goodscnt",$goodCnt);
		$iniescrow->SetField("price",$price);
		$iniescrow->SetField("dlv_reserved1",$reserved1);
		$iniescrow->SetField("dlv_reserved2",$reserved2);
		$iniescrow->SetField("dlv_reserved3",$reserved3);
		
		$iniescrow->SetField("pgn",$pgn);

		/*********************
		 * 3. 배송 등록 요청 *
		 *********************/
		$iniescrow->startAction();
		
		
		/**********************
		 * 4. 배송 등록  결과 *
		 **********************/
		 
		$tid        = $iniescrow->GetResult("tid"); 					// 거래번호
		$resultCode = $iniescrow->GetResult("ResultCode");		// 결과코드 ("00"이면 지불 성공)
		$resultMsg  = $iniescrow->GetResult("ResultMsg"); 			// 결과내용 (지불결과에 대한 설명)
		$dlv_date   = $iniescrow->GetResult("DLV_Date");
		$dlv_time   = $iniescrow->GetResult("DLV_Time");

		Context::set('tid', $tid);
		Context::set('resultCode', $resultCode);
		Context::set('resultMsg', $resultMsg);
		Context::set('dlv_date', $dlv_date);
		Context::set('dlv_time', $dlv_time);


		$oTemplate = &TemplateHandler::getInstance();
		$tpl_path = _XE_PATH_."modules/svpg/plugins/iniescrow/tpl";
		$tpl_file = 'escrow_delivery_result.html';
		$tpl = $oTemplate->compile($tpl_path, $tpl_file);
		$output = new BaseObject();
		if(!$resultCode != '00') $output->setError(-1);
		$output->add('order_srl', $order_srl);
		$output->add('pg_tid', $tid);
		$output->add('pg_oid', $oid);
		$output->add('invoice_no', $invoice);
		$output->add('registrant', $dlv_name);
		$output->add('deliverer_code', $dlv_exCode);
		$output->add('deliverer_name', $dlv_exName);
		$output->add('delivery_type', ($dlv_charge == 'SH' ? '1':'2'));
		$output->add('delivery_date', $dlv_invoiceday);
		$output->add('sender_name', $sendName);
		$output->add('sender_postcode', $sendPost);
		$output->add('sender_address1', $sendAddr1);
		$output->add('sender_address2', $sendAddr2);
		$output->add('sender_telnum', $sendTel);
		$output->add('recipient_name', $recvName);
		$output->add('recipient_postcode', $recvPost);
		$output->add('recipient_address', $recvAddr);
		$output->add('recipient_telnum', $recvTel);
		$output->add('product_code', $goodsCode);
		$output->add('product_name', $goods);
		$output->add('quantity', $goodCnt);
		$output->add('price', $price);
		$output->add('result_code', $resultCode);
		$output->add('result_message', iconv('EUC-KR','UTF-8',$resultMsg));
		$output->setMessage($tpl);
		return $output;
	}

	function procEscrowConfirm()
	{
		$inipayhome = sprintf(_XE_PATH_."files/svpg/%s", $this->plugin_info->plugin_srl);

		$vars = Context::getRequestVars();
		debugPrint('procEscrowConfirm');
		debugPrint($vars);

		extract(get_object_vars($vars));

		debugPrint('encrypted : ' . $encrypted);
		debugPrint('sessionkey : ' . $sessionkey);

		require("libs/INILib.php");
		$iniescrow = new INIpay50;
		$iniescrow->SetField("inipayhome", $inipayhome);
		$iniescrow->SetField("tid", Context::get('tid'));
		$iniescrow->SetField("mid", $this->plugin_info->inicis_id);
		$iniescrow->SetField("admin", $this->plugin_info->inicis_pass);
		$iniescrow->SetField("type", "escrow");
		$iniescrow->SetField("escrowtype", "confirm");
		$iniescrow->SetField("debug", "true");

		$iniescrow->SetField("encrypted",$encrypted);
		$iniescrow->SetField("sessionkey",$sessionkey);

		/*********************
		 * 3. 구매확인 요청 *
		 *********************/
		$iniescrow->startAction();
		
		
		/**********************
		 * 4. 구매확인  결과 *
		 **********************/
		 
		$tid          = $iniescrow->GetResult("tid"); 		// 거래번호
		$resultCode   = $iniescrow->GetResult("ResultCode");	// 결과코드 ("00"이면 지불 성공)
		$resultMsg    = $iniescrow->GetResult("ResultMsg");    // 결과내용 (지불결과에 대한 설명)
		$resultDate   = $iniescrow->GetResult("CNF_Date");    // 처리 날짜
		$resultTime   = $iniescrow->GetResult("CNF_Time");    // 처리 시각

		if($resultDate=="" )
		{
			 $resultDate   = $iniescrow->GetResult("DNY_Date");    // 처리 날짜
			 $resultTime   = $iniescrow->GetResult("DNY_Time");    // 처리 시각
		}

		Context::set('tid', $tid);
		Context::set('resultCode', $resultCode);
		Context::set('resultMsg', $resultMsg);
		Context::set('resultDate', $resultDate);
		Context::set('resultTime', $resultTime);


		debugPrint('$resultDate.$resultTime');
		debugPrint($resultDate.$resultTime);

		$oTemplate = &TemplateHandler::getInstance();
		$tpl_path = _XE_PATH_."modules/svpg/plugins/iniescrow/tpl";
		$tpl_file = 'escrow_confirm_result.html';
		$tpl = $oTemplate->compile($tpl_path, $tpl_file);
		$output = new BaseObject();
		if(!$resultCode != '00') $output->setError(-1);
		$output->add('order_srl', Context::get('order_srl'));
		$output->add('confirm_code', $resultCode);
		$output->add('confirm_message', iconv('EUC-KR','UTF-8',$resultMsg));
		$output->add('confirm_date', $resultDate.$resultTime);
		$output->setMessage($tpl);
		return $output;
	}

	function procEscrowDenyConfirm()
	{
		$inipayhome = sprintf(_XE_PATH_."files/svpg/%s", $this->plugin_info->plugin_srl);

		$vars = Context::getRequestVars();
		debugPrint('procEscrowDenyConfirm');
		debugPrint($vars);
		extract(get_object_vars($vars));

		/**************************
		 * 1. 라이브러리 인클루드 *
		 **************************/
		require("libs/INILib.php");
		
		
		/***************************************
		 * 2. INIpay50 클래스의 인스턴스 생성 *
		 ***************************************/
		$iniescrow = new INIpay50;
		
		/*********************
		 * 3. 지불 정보 설정 *
		 *********************/
		$iniescrow->SetField("inipayhome", $inipayhome);
		$iniescrow->SetField("tid", Context::get('tid'));
		$iniescrow->SetField("mid", $this->plugin_info->inicis_id);
		$iniescrow->SetField("admin", $this->plugin_info->inicis_pass);
		$iniescrow->SetField("type", "escrow");
		$iniescrow->SetField("escrowtype", "dcnf"); 				                    // 고정 (절대 수정 불가)
		$iniescrow->SetField("dcnf_name",$dcnf_name);
		$iniescrow->SetField("debug", "true");

		/*********************
		 * 3. 거절확인 요청 *
		 *********************/
		$iniescrow->startAction();
		
		
		/**********************
		 * 4. 거절확인  결과 *
		 **********************/
		 
		debugPrint($iniescrow->m_Data);
		$tid          = $iniescrow->GetResult("tid"); 					// 거래번호
		$resultCode   = $iniescrow->GetResult("ResultCode");		// 결과코드 ("00"이면 지불 성공)
		$resultMsg    = $iniescrow->GetResult("ResultMsg");    // 결과내용 (지불결과에 대한 설명)
		$resultDate   = $iniescrow->GetResult("DCNF_Date");    // 처리 날짜
		$resultTime   = $iniescrow->GetResult("DCNF_Time");    // 처리 시각

		Context::set('tid', $tid);
		Context::set('resultCode', $resultCode);
		Context::set('resultMsg', $resultMsg);
		Context::set('resultDate', $resultDate);
		Context::set('resultTime', $resultTime);
		
		$oTemplate = &TemplateHandler::getInstance();
		$tpl_path = _XE_PATH_."modules/svpg/plugins/iniescrow/tpl";
		$tpl_file = 'escrow_denyconfirm_result.html';
		$tpl = $oTemplate->compile($tpl_path, $tpl_file);
		$output = new BaseObject();
		if(!$resultCode != '00') $output->setError(-1);
		$output->add('order_srl', Context::get('order_srl'));
		$output->add('denyconfirm_code', $resultCode);
		$output->add('denyconfirm_message', iconv('EUC-KR','UTF-8',$resultMsg));
		$output->add('denyconfirm_date', $resultDate.$resultTime);
		$output->setMessage($tpl);
		return $output;

	}

	function getPaymethod($paymethod)
	{
		switch ($paymethod) {
			case 'VBank':
				return 'VA';
			case 'Card':
			case 'VCard':
				return 'CC';
			case 'HPP':
				return 'MP';
			case 'DirectBank':
				return 'IB';
			default:
				return '  ';
		}
	}

	function getBankName($code)
	{
	    switch($code) {
		case "03" : return "기업은행"; break;
		case "04" : return "국민은행"; break;
		case "05" : return "외환은행"; break;
		case "07" : return "수협중앙회"; break;
		case "11" : return "농협중앙회"; break;
		case "20" : return "우리은행"; break;
		case "23" : return "SC제일은행"; break;
		case "31" : return "대구은행"; break;
		case "32" : return "부산은행"; break;
		case "34" : return "광주은행"; break;
		case "37" : return "전북은행"; break;
		case "39" : return "경남은행"; break;
		case "53" : return "한국씨티은행"; break;
		case "71" : return "우체국"; break;
		case "81" : return "하나은행"; break;
		case "88" : return "통합신한은행(신한,조흥은행)"; break;
		case "D1" : return "동양종합금융증권"; break;
		case "D2" : return "현대증권"; break;
		case "D3" : return "미래에셋증권"; break;
		case "D4" : return "한국투자증권"; break;
		case "D5" : return "우리투자증권"; break;
		case "D6" : return "하이투자증권"; break;
		case "D7" : return "HMC투자증권"; break;
		case "D8" : return "SK증권"; break;
		case "D9" : return "대신증권"; break;
		case "DB" : return "굿모닝신한증권"; break;
		case "DC" : return "동부증권"; break;
		case "DD" : return "유진투자증권"; break;
		case "DE" : return "메리츠증권"; break;
		case "DF" : return "신영증권"; break;
		default   : return ""; break;
	    }
	}
}
/* End of file iniescrow.plugin.php */
/* Location: ./modules/svpg/plugins/iniescrow/iniescrow.plugin.php */
