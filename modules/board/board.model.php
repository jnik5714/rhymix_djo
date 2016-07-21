<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */

/**
 * @class  boardModel
 * @author NAVER (developers@xpressengine.com)
 * @brief  board module  Model class
 **/
class boardModel extends module
{
	/**
	 * @brief initialization
	 **/
	function init()
	{
	}

	/**
	 * @brief get the list configuration
	 **/
	function getListConfig($module_srl) {
		$oModuleModel = getModel('module');
		$oDocumentModel = getModel('document');

		// get the list config value, if it is not exitsted then setup the default value
            $list_config = $oModuleModel->getModulePartConfig('board', $module_srl);
            if(!$list_config || !count($list_config)) $list_config = array( 'no', 'title', 'nick_name','regdate','readed_count');

		// get the extra variables
		$inserted_extra_vars = $oDocumentModel->getExtraKeys($module_srl);

		foreach($list_config as $key) {
			if(preg_match('/^([0-9]+)$/',$key))
			{
				if($inserted_extra_vars[$key])
				{
					$output['extra_vars'.$key] = $inserted_extra_vars[$key];
				}
				else
				{
					continue;
				}
			}
			else $output[$key] = new ExtraItem($module_srl, -1, Context::getLang($key), $key, 'N', 'N', 'N', null);
		}
		return $output;
	}

	/**
	 * @brief return the default list configration value
	 **/
	function getDefaultListConfig($module_srl)
	{
		// add virtual srl, title, registered date, update date, nickname, ID, name, readed count, voted count etc.
		$virtual_vars = array( 'no', 'title', 'regdate', 'last_update', 'last_post', 'nick_name',
				'user_id', 'user_name', 'readed_count', 'voted_count', 'blamed_count', 'thumbnail', 'summary', 'comment_status', 'doc_state');
		foreach($virtual_vars as $key)
		{
			$extra_vars[$key] = new ExtraItem($module_srl, -1, Context::getLang($key), $key, 'N', 'N', 'N', null);
		}

		// get the extra variables from the document model
		$oDocumentModel = getModel('document');
		$inserted_extra_vars = $oDocumentModel->getExtraKeys($module_srl);

		if(count($inserted_extra_vars))
		{
			foreach($inserted_extra_vars as $obj)
			{
				$extra_vars['extra_vars'.$obj->idx] = $obj;
			}
		}

		return $extra_vars;

	}

	/**
	 * @brief return module name in sitemap
	 **/
	function triggerModuleListInSitemap(&$obj)
	{
		array_push($obj, 'board');
	}
	
        /**
         * @ 문서타입
         **/
        function getBoardDocumentType($document_srl) {
            $queryid = "board.getBoardDocumentType";
            $args->document_srl = $document_srl;
            $output = executeQuery($queryid, $args);
            return $output->data;
        }

        function getBestDocumentList($module_srl = 0, $opt_args = null) {
            $oModuleModel = &getModel('module');

            if(!$module_srl){
                $mid = Context::get('mid');
                if($mid){
                    $module_info = $oModuleModel->getModuleInfoByMid($mid);
                    $module_srl = $module_info->module_srl;
                }
            }
            else
            {
                $module_info = $oModuleModel->getModuleInfoByModuleSrl($module_srl);
                $module_srl = $module_info->module_srl;
            }

            if(!$module_srl)  return;

            $best_date_range = $opt_args->best_date_range;
            $best_list_count = $opt_args->best_list_count;
            $best_sort_index = $opt_args->best_sort_index;
            if(!is_numeric($best_date_range)||$best_date_range<='0') $best_date_range = '14';
            if(!is_numeric($best_list_count)||$best_list_count<='0') $best_list_count = '3';
            if(!in_array($best_sort_index, array('voted_count','readed_count','comment_count'))) $best_sort_index = 'voted_count';

            $args->module_srl = $module_srl;
            $args->list_count = $best_list_count;
            $args->page_count = 1;
            $args->sort_index =  $best_sort_index;
            $args->order_type = 'desc';

            $week = date("YmdHis", strtotime("-$best_date_range day"));
            $args->search_target = 'best_'.$best_sort_index;
            $args->search_keyword = $week;

            $output = $this->getDocumentList($args, true, $this->columnList);

            return $output->data;
        }
        
        /**
         * @brief 베스트 댓글 구하기
         **/
        function getBestCommentList($document = null) {
            if(!$document) $document_srl = Context::get('document_srl');

            if($document_srl){
                $oDocumentModel = &getModel('document');
                $document = $oDocumentModel->getDocument($document_srl, false, false);
            }

            if(!$document || !$document->isExists())  return;

            if(!$document->allowComment() || !$document->getCommentCount()) return;
            if(!$document->isGranted() && $document->isSecret()) return;

            $best_date_range = $this->module_info->best_date_range;
            $best_list_count = $this->module_info->best_list_count;
            if(!is_numeric($best_date_range)||$best_date_range<='0') $best_date_range = '7';
            if(!is_numeric($best_list_count)||$best_list_count<='0') $best_list_count = '2';

            $args->document_srl = $document->document_srl;
            $args->list_count = $best_list_count;
            $args->page_count = 5;
            $args->sort_index =  'voted_count';
            $args->order_type = 'desc';

            $week = date("YmdHis", strtotime("-$best_date_range day"));
            $args->best_voted_count = '2';
            $args->best_regdate = $week;
            $args->best_secret = 'N';

            $output = executeQueryArray('board.getCommentList', $args);
            if(!$output->toBool() || !count($output->data)) return;

            // 구해온 목록을 commentItem 객체로 만듬
            // 계층구조에 따라 부모글에 관리권한이 있으면 자식글에는 보기 권한을 줌
            $accessible = array();
            require_once(_XE_PATH_.'modules/comment/comment.item.php');

            foreach($output->data as $key => $val) {
                $oCommentItem = new commentItem();
                $oCommentItem->setAttribute($val);

                // 권한이 있는 글에 대해 임시로 권한이 있음을 설정
                if($oCommentItem->isGranted()) $accessible[$val->comment_srl] = true;

                // 현재 댓글이 비밀글이고 부모글이 있는 답글이고 부모글에 대해 관리 권한이 있으면 보기 가능하도록 수정
                if($val->parent_srl>0 && $val->is_secret == 'Y' && !$oCommentItem->isAccessible() && $accessible[$val->parent_srl] === true) {
                    $oCommentItem->setAccessible();
                }
                $comment_list[$val->comment_srl] = $oCommentItem;
            }

            return $comment_list;
        }
        /**
         * @brief 문서 목록 구하기
         **/
        function getDocumentList($obj, $except_notice = false) {
            // 기본으로 사용할 query id 지정 (몇가지 옵션에 따라 query id가 변경됨)
            $query_id = 'board.getDocumentList';
            // 직접 처리해야할 검색 타겟(사용안함)
            $board_search_target = array('doc_state','best_voted_count','best_readed_count','best_comment_count');
            $board_order_target = array('doc_state', 'list_order', 'last_updater', 'nick_name', 'user_id', 'user_name', 'email_address', 'homepage', 'ipaddress','blamed_count','thumbnail','summary', 'readed_count',);
            if(!in_array($obj->order_type, array('desc','asc'))) $obj->order_type = 'asc';

            $oModuleModel = &getModel('module');
            $oDocumentModel = &getModel('document');

            if(!$module_srl){
                $mid = Context::get('mid');
                if($mid){
                    $module_info = $oModuleModel->getModuleInfoByMid($mid);
                    $module_srl = $module_info->module_srl;
                }
            }
            else
            {
                $module_info = $oModuleModel->getModuleInfoByModuleSrl($module_srl);
                $module_srl = $module_info->module_srl;
            }
            
            $sort_index = $obj->sort_index;
            $search_target = $obj->search_target;
            $search_keyword = $obj->search_keyword;

            // 넘어온 module_srl은 array일 수도 있기에 array인지를 체크
            if(is_array($obj->module_srl)) $args->module_srl = implode(',', $obj->module_srl);
            else $args->module_srl = $obj->module_srl;

            // 제외 module_srl에 대한 검사
            if(is_array($obj->exclude_module_srl)) $args->exclude_module_srl = implode(',', $obj->exclude_module_srl);
            else $args->exclude_module_srl = $obj->exclude_module_srl;

            // 변수 체크
            $args->sort_index = $obj->sort_index?$obj->sort_index:'list_order';
            $args->order_type = $obj->order_type;
            $args->category_srl = $obj->category_srl?$obj->category_srl:null;
            $args->page = $obj->page?$obj->page:1;
            $args->list_count = $obj->list_count?$obj->list_count:20;
            $args->page_count = $obj->page_count?$obj->page_count:10;
            $args->member_srl = $obj->member_srl;
		if(Context::get('sort_index')=='best')
    		{
        		$args->s2_readed_count = $module_info->s2_readed_count?$module_info->s2_readed_count:30;
    		}
		if(Context::get('sort_index')=='bob')
    		{
        		$args->s2_voted_count = $module_info->s2_voted_count?$module_info->s2_voted_count:5;
        		$args->s2_readed_count = $module_info->s2_readed_count?$module_info->s2_readed_count:100;
    		}
            /* 카테고리가 선택되어 있으면 하부 카테고리까지 모두 조건에 추가
            */
            if($args->category_srl) {
                $category_list = $oDocumentModel->getCategoryList($args->module_srl);
                $category_info = $category_list[$args->category_srl];
                $category_info->childs[] = $args->category_srl;
                $args->category_srl = implode(',',$category_info->childs);
            }

            /* 정렬 옵션 정리 */
            switch($sort_index) {
                case 'doc_state' :
                    $args->sort_index = 'is_notice';
                    break;
			case 'title' :
			case 'content' :
				if($search_keyword) $search_keyword = str_replace(' ','%',$search_keyword);
				$args->{"s_".$search_target} = $search_keyword;
				$use_division = true;
				break;
			case 'title_content' :
				if($search_keyword) $search_keyword = str_replace(' ','%',$search_keyword);
				$args->s_title = $search_keyword;
				$args->s_content = $search_keyword;
				$use_division = true;
				break;
			case 'user_id' :
				if($search_keyword) $search_keyword = str_replace(' ','%',$search_keyword);
				$args->s_user_id = $search_keyword;
				$args->sort_index = 'documents.'.$args->sort_index;
				break;
			case 'user_name' :
			case 'nick_name' :
			case 'email_address' :
			case 'homepage' :
				if($search_keyword) $search_keyword = str_replace(' ','%',$search_keyword);
				$args->{"s_".$search_target} = $search_keyword;
				break;
			case 'is_notice' :
				if($search_keyword=='N') $args->{"s_".$search_target} = 'N';
				elseif($search_keyword=='Y') $args->{"s_".$search_target} = 'Y';
				else $args->{"s_".$search_target} = '';
				break;
			case 'is_secret' :
				if($search_keyword=='N') $args->statusList = array($this->getConfigStatus('public'));
				elseif($search_keyword=='Y') $args->statusList = array($this->getConfigStatus('secret'));
				elseif($search_keyword=='temp') $args->statusList = array($this->getConfigStatus('temp'));
				break;
			case 'member_srl' :
			case 'readed_count' :
			case 'voted_count' :
			case 'comment_count' :
			case 'trackback_count' :
			case 'uploaded_count' :
				$args->{"s_".$search_target} = (int)$search_keyword;
				break;
			case 'blamed_count' :
					$args->{"s_".$search_target} = (int)$search_keyword * -1;
				break;
			case 'regdate' :
			case 'last_update' :
			case 'ipaddress' :
				$args->{"s_".$search_target} = $search_keyword;
				break;
			case 'comment' :
				$args->s_comment = $search_keyword;
				$query_id = 'document.getDocumentListWithinComment';
				$use_division = true;
				break;
			case 'tag' :
				$args->s_tags = str_replace(' ','%',$search_keyword);
				$use_division = true;
				break;
			case 'extra_vars':
				$args->var_value = str_replace(' ', '%', $search_keyword);
				$query_id = 'document.getDocumentListWithinExtraVars';
				break;
                default :
                        if(strpos($sort_index,'extra_vars') === 0) {
                            $args->sort_index = 'extra_vars.value';
                            $args->var_idx = substr($sort_index, strlen('extra_vars'));
                            $query_id = 'board.getDocumentListWithExtraVars';
                        }
                    break;
            }

            // 검색 옵션 정리
            if($search_target && ($search_keyword || (string) $search_keyword === '0')) {
                // 지원하는 검색이므로 위에 도큐먼트 모듈 함수에서 처리됨
                //$use_division = ($args->sort_index == 'list_order' && $args->order_type == 'asc') && in_array($search_target, array('title','content','title_content','comment'));

                switch($search_target) {
                    case 'title_content' :
                            if($search_keyword) $search_keyword = str_replace(' ','%',$search_keyword);
                            $args->s_title = $search_keyword;
                            $args->s_content = $search_keyword;
                        break;
                    case 'is_notice' :
                    case 'is_secret' :
                            if($search_keyword=='N') $args->{"s_".$search_target} = 'N';
                            elseif($search_keyword=='Y') $args->{"s_".$search_target} = 'Y';
                            else $args->{"s_".$search_target} = '';
                        break;
                    case 'member_srl' :
                    case 'readed_count' :
                    case 'voted_count' :
                    case 'comment_count' :
                    case 'trackback_count' :
                    case 'uploaded_count' :
                            $args->{"s_".$search_target} = (int)$search_keyword;
                        break;
                    case 'doc_state' :
                            if((string) $search_keyword === '0') $search_keyword = "'0','N'";
                            $args->s_doc_state = $search_keyword;
                        break;
                    case 'best_voted_count' :
                    case 'best_readed_count' :
                    case 'best_comment_count' :
                            $args->{$search_target} = '1';
                            $args->best_regdate = $search_keyword;
                        break;
                    case 'comment' :
                            $args->s_comment = $search_keyword;
                            $query_id = 'document.getDocumentListWithinComment';
                        break;
                    case 'tag' :
                            $args->s_tags = str_replace(' ','%',$search_keyword);
                        break;
                    default :
                            if(strpos($search_target,'extra_vars') === 0) {
                                $args->var_idx = substr($search_target, strlen('extra_vars'));
                                $args->var_value = str_replace(' ','%',$search_keyword);
                                if($args->sort_index != 'extra_vars.value') $args->sort_index = 'documents.'.$args->sort_index;
                                $query_id = 'board.getDocumentListWithExtraVars';
                            }else{
                                if($search_keyword) $search_keyword = str_replace(' ','%',$search_keyword);
                                $args->{"s_".$search_target} = $search_keyword;
                            }
                        break;
                }
            }
            $output = executeQueryArray($query_id, $args);
            // 결과가 없거나 오류 발생시 그냥 return
            if(!$output->toBool()||!count($output->data)) return $output;
		return $this->_makeDocumentsListStatic($output);
        }


        /**
         * @brief 인기글 위젯 문서 구하기
         **/
        function getBestList($obj) {
            $query_id = 'board.getBestList';
            $oModuleModel = &getModel('module');
            $oDocumentModel = &getModel('document');

		if(!$obj->module_srl){
			$mid = Context::get('mid');
			if($mid){
				$module_info = $oModuleModel->getModuleInfoByMid($mid);
				$module_srl = $module_info->module_srl;
			}
            }
            else
            {
                $module_srl = $obj->module_srl;
            }
            
            if(!$module_srl)  return;
            
            // 넘어온 module_srl은 array일 수도 있기에 array인지를 체크
            if(is_array($module_srl)) $args->module_srl = implode(',', $module_srl);
            else $args->module_srl = $module_srl;

		$args->voted_count = $obj->voted_count?$obj->voted_count:null;
		$args->category_srl = $obj->category_srl?$obj->category_srl:null;
		$args->readed_count = $obj->readed_count?$obj->readed_count:30;
		$args->list_count = $obj->list_count;
		$args->sort_index =  list_order;
		$args->order_type = $obj->order_type?$obj->order_type:desc;

            $output = executeQueryArray($query_id, $args);
            // 결과가 없거나 오류 발생시 그냥 return
            if(!$output->toBool()||!count($output->data)) return $output;
		return $this->_makeDocumentsListStatic($output);
        }
        
	function _makeDocumentsListStatic(&$output, $except_notice = false)
	{
            $idx = 0;
            $data = $output->data;
            unset($output->data);

            if(!isset($virtual_number)) {
                $keys = array_keys($data);
                $virtual_number = $keys[0];
            }
            foreach($data as $key => $attribute) {
                $document_srl = $attribute->document_srl;
                if(!$GLOBALS['XE_DOCUMENT_LIST'][$document_srl]) {
                    $oDocument = null;
                    $oDocument = new documentItem();
                    $oDocument->setAttribute($attribute, false);
                    if($is_admin) $oDocument->setGrant();
                    $GLOBALS['XE_DOCUMENT_LIST'][$document_srl] = $oDocument;
                }

                $output->data[$virtual_number] = $GLOBALS['XE_DOCUMENT_LIST'][$document_srl];
                $virtual_number --;
            }
            if(count($output->data)) {
                foreach($output->data as $number => $document) {
                    $output->data[$number] = $GLOBALS['XE_DOCUMENT_LIST'][$document->document_srl];
                }
            }

            return $output;
	}
}
