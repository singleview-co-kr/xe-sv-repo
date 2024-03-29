<?php
/**
 * @class  svbannerModel
 * @author singleview(root@singleview.co.kr)
 * @brief  svbannerModel
 */
require_once(_XE_PATH_.'modules/svbanner/CircularLinkedList.class.php');
class svbannerModel extends svbanner
{
	private $_sNoImgUrl;

	function __construct()
    {
		$this->_sNoImgUrl = Context::getRequestUri().'/modules/svbanner/tpl/img/no_img_80x80.jpg';
	}
/**
 * @brief
 * @return 
 **/
	public function init() 
	{
	}
/**
 * @brief
 * @return 
 **/
	public function getModuleConfig()
	{
		$oModuleModel = &getModel('module');
		$oConfig = $oModuleModel->getModuleConfig('svbanner');
		if(is_null($oConfig))
			$oConfig = new stdClass();
		if(!$oConfig->duplicated_click_limit_day) 
			$oConfig->duplicated_click_limit_day = '0.0104';  // 0.0104=15/1440 means 15 min
		return $oConfig;
	}
/**
 * @brief get mid level config
 * @return 
 **/
	public function getMidLevelConfig($nModuleSrl)
	{
		if(!$nModuleSrl)
			return new BaseObject(-1, 'msg_invalid_module_srl');
		$oModuleModel = &getModel('module');
		return $oModuleModel->getModuleInfoByModuleSrl($nModuleSrl);
	}
/**
* @brief get current banner info
* @return $oRst->sUniqId, $oRst->sBannerImgUrl, $oRst->sScript
**/
	public static function getCurrentBannerImgUrl($nModuleSrl, $aBannerDim)
	{
		$sModuleTplPath = '/modules/svbanner/tpl';
		Context::addJsFile($sModuleTplPath.'/skin.js/sv_banner_internal.js');
		Context::addJsFile($sModuleTplPath.'/skin.js/jquery.cookie.js');

		// Unable to use $this as not in object context
		$oSvbannerModel = &getModel('svbanner');
		if(count($aBannerDim) != 2)
		{
			$oRst = new stdClass();
			$oRst->nImpLogSrl = 0;
			$oRst->nBannerSrl = 0;
			$oRst->sBannerImgUrl = Context::getRequestUri().$sModuleTplPath.'/img/no_img_80x80.jpg';
			$oRst->sLandingUrl = '#';
		}
		else
			$oRst = $oSvbannerModel->getCurrentBanner($nModuleSrl, $aBannerDim);

		// 여기서 정상 배너 이미지 소재가 없으면 기본 이미지로 교체
		$oRst->sUniqId = uniqid();
		$oConfig = $oSvbannerModel->getModuleConfig();
		unset($oSvbannerModel);

		$sScript = FileHandler::readFile(_XE_PATH_.'modules/svbanner/tpl/skin.js/internal_script.tpl');
		$sScript = str_replace('$%sUniqId$%', $oRst->sUniqId, $sScript);
		$sScript = str_replace('$%nImpLogSrl$%', $oRst->nImpLogSrl, $sScript);
		$sScript = str_replace('$%nBannerSrl$%', $oRst->nBannerSrl, $sScript);
		$sScript = str_replace('$%fDuplicatedClickLimitDay$%', $oConfig->fDuplicatedClickLimitDay, $sScript);
        $sScript = str_replace('$%sLandingUrl$%', $oRst->sLandingUrl, $sScript);
		$oRst->sScript = $sScript;
        unset($oRst->nImpLogSrl);
        unset($oRst->nImpLogSrl);
        unset($oRst->nBannerSrl);
        unset($oRst->sLandingUrl);
        unset($oConfig);
		return $oRst;
	}
/**
* @brief get current banner info
* @return 
**/
	public function getCurrentBanner($nModuleSrl, $aBannerDim) 
	{
		$aBannerScheduleByBannerSrl = $this->_getBannerScheduleToday($nModuleSrl, $aBannerDim);
		
		$oImpArg = new stdClass();
		$oImpArg->img_width = $aBannerDim[0];
		$oImpArg->img_height = $aBannerDim[1];
		$oImpressionRst = executeQuery('svbanner.getImpLogByBannerSpecSrl', $oImpArg);
		unset($oImpArg);
		if(!$oImpressionRst->toBool())
			return $oImpressionRst;
			
		// retrieve banner
		if(!count((array)$oImpressionRst->data))  // get head of displaying schedule
			$oCurBanner = reset($aBannerScheduleByBannerSrl);
		else  // get next displaying schedule
		{
			$nBannerSrl = $oImpressionRst->data->banner_srl;
			$nNextBannerSrl = $aBannerScheduleByBannerSrl[$nBannerSrl]->next_banner_srl;
			$oCurBanner = $aBannerScheduleByBannerSrl[$nNextBannerSrl];
		}
		if(!$oCurBanner)  // if daily schedule has been changed
			$oCurBanner = reset($aBannerScheduleByBannerSrl);
//var_dump($oCurBanner);
//exit;
		if($oCurBanner)  // 계약이 있으면
		{
			$oNewImpArg = new stdClass();
			$oNewImpArg->img_width = $aBannerDim[0];
			$oNewImpArg->img_height = $aBannerDim[1];
			$oNewImpArg->banner_srl = $oCurBanner->banner_srl;
			$oNewImpArg->package_srl = $oCurBanner->package_srl;
			$oImpressionAddRst = executeQueryArray('svbanner.insertImpLog', $oNewImpArg);
			unset($oNewImpArg);
			if(!$oImpressionAddRst->toBool())
				return $oImpressionAddRst;

			$oDB = DB::getInstance();
			$nImpLogSrl = $oDB->db_insert_id();
			unset($oDB);

			//$oRst = new stdClass();
			//$oRst->nImpLogSrl = $nImpLogSrl;
			//$oRst->sBannerImgUrl = $oCurBanner->img_file_url;
			$nImpFileUrl = $oCurBanner->img_file_url;
			//$oRst->nBannerSrl = $oCurBanner->banner_srl;
			$nBannerSrl = $oCurBanner->banner_srl;
			//$oRst->sLandingUrl = $oCurBanner->landing_url;
			$sLandingUrl = $oCurBanner->landing_url;
		}
		else
		{
			$nImpLogSrl = -1;
			$nImpFileUrl = $this->_sNoImgUrl;
			$nBannerSrl = -1;
			$sLandingUrl = '#';
		}
		$oRst = new stdClass();
		$oRst->nImpLogSrl = $nImpLogSrl;
		$oRst->sBannerImgUrl = $nImpFileUrl;
		$oRst->nBannerSrl = $nBannerSrl;
		$oRst->sLandingUrl = $sLandingUrl;
		return $oRst;
	}
/**
 * @brief get banner displaying schedule for today based on serialized circular linked list
 * @return 
 **/
	private function _getBannerScheduleToday($nModuleSrl, $aBannerDim) 
	{
		$sTodayYyyymmdd = Date('Ymd');
		// load schedule cache and return, if exists
		$sScheduleCacheFilePathAbs = $this->_g_DailyScheduleCachePath.'/'.$nModuleSrl.'_'.$aBannerDim[0].'_'.$aBannerDim[1].'_'.$sTodayYyyymmdd.'.cache.php';
		$sTodayScheduleCacheFile = FileHandler::readFile($sScheduleCacheFilePathAbs);
		if($sTodayScheduleCacheFile)
			return unserialize($sTodayScheduleCacheFile);
		
		// construct schedule cache and return
		$oArg = new stdClass();
		$oArg->module_srl = $nModuleSrl;
		$oArg->is_active = 'Y';
		$oContractRst = executeQueryArray('svbanner.getContractListByModuleSrl', $oArg);
		$aCorrespondingPkg = [];
		foreach($oContractRst->data as $nIdx => $oContract)
		{
			if((int)$oContract->begin_date <= (int)($sTodayYyyymmdd.'000000') && (int)$oContract->end_date >= (int)($sTodayYyyymmdd.'235959'))
				$aCorrespondingPkg[] = $oContract->package_srl;
		}
		unset($oArg);
		if(!$oContractRst->toBool())
			return $oContractRst;
		unset($oContractRst);

		$oArg = new stdClass();
		if(count($aCorrespondingPkg))
			$oArg->package_srl = implode(',', $aCorrespondingPkg);
		else
			$oArg->package_srl = -123;  // search unavailable
		unset($aCorrespondingPkg);
		$oArg->img_width = $aBannerDim[0];
		$oArg->img_height = $aBannerDim[1];
		$oBannerRst = executeQueryArray('svbanner.getBannerListByPackageSrl', $oArg);
		unset($oArg);
		if(!$oBannerRst->toBool())
			return $oBannerRst;
		
		$oPkgArg = new stdClass();
		$oCll = new CircularLinkedList();
		// construct banner display schedule
		foreach($oBannerRst->data as $nIdx=>$oBanner)
		{
			$oPkgArg->package_srl = $oBanner->package_srl;
			$oPackageRst = executeQuery('svbanner.getPackageByPackageSrl', $oPkgArg);
			if(!$oPackageRst->toBool())
				return $oPackageRst;
			$oBanner->landing_url = $oPackageRst->data->landing_url;
			unset($oPackageRst);
			$oCll->push($oBanner);
		}
		unset($oPkgArg);
		unset($oBanner);

		$nBannerCnt = $oCll->getNodeCnt();
		$oCll->reset();
		$aBannerScheduleByBannerSrl = [];
		if($nBannerCnt)
		{
			$oFileModel = &getModel('file');
			$sServerUrl = Context::getDefaultUrl();
			// construct serialzed circular linked list
			for($j = 1; $j <= $nBannerCnt; $j++) 
			{
				$oCurSingleBanner = $oCll->getCurrent();
				$sBannerImgUrl = null;
				$oBannerImgFile = $oFileModel->getFile($oCurSingleBanner->nImgFileSrl);
				if($oBannerImgFile)
					$sBannerImgUrl = $sServerUrl.$oBannerImgFile->uploaded_filename;
				unset($oBannerImgFile);
				
				$aBannerScheduleByBannerSrl[$oCurSingleBanner->nBannerSrl] = new stdClass();
				$aBannerScheduleByBannerSrl[$oCurSingleBanner->nBannerSrl]->banner_srl = $oCurSingleBanner->nBannerSrl;
				$aBannerScheduleByBannerSrl[$oCurSingleBanner->nBannerSrl]->package_srl = $oCurSingleBanner->nPackageSrl;
				$aBannerScheduleByBannerSrl[$oCurSingleBanner->nBannerSrl]->img_file_url = $sBannerImgUrl;
				$aBannerScheduleByBannerSrl[$oCurSingleBanner->nBannerSrl]->landing_url = $oCurSingleBanner->sLandingUrl;
				$oNextSingleBanner = $oCll->getNext();
				$aBannerScheduleByBannerSrl[$oCurSingleBanner->nBannerSrl]->next_banner_srl = $oNextSingleBanner->nBannerSrl;
				$oCll->moveNext();
				unset($oCurSingleBanner);
				unset($oNextSingleBanner);
			}
			unset($oFileModel);
		}
		unset($oCll);
		$sSerializedRst = serialize($aBannerScheduleByBannerSrl);
		FileHandler::writeFile($sScheduleCacheFilePathAbs, $sSerializedRst);
		return $aBannerScheduleByBannerSrl;
	}
}
/* End of file svitem.model.php */
/* Location: ./modules/svitem/svitem.model.php */