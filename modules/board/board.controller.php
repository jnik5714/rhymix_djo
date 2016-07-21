<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */

/**
 * @class  boardController
 * @author NAVER (developers@xpressengine.com)
 * @brief  board module Controller class
 **/

class boardController extends board
{

	/**
	 * @brief initialization
	 **/
	function init()
	{
	}

	/**
	 * @brief insert document
	 **/
	function procBoardInsertDocument()
	{
		// check grant
		if($this->module_info->module != "board")
		{
			return new Object(-1, "msg_invalid_request");
		}
		$logged_info = Context::get('logged_info');

		// setup variables
		$obj = Context::getRequestVars();
		$obj->module_srl = $this->module_srl;
		if($obj->is_notice!='Y'||!$this->grant->manager) $obj->is_notice = 'N';
		$obj->commentStatus = $obj->comment_status;

		settype($obj->title, "string");
		if($obj->title == '') $obj->title = cut_str(strip_tags($obj->content),20,'...');
		//setup dpcument title tp 'Untitled'
		if($obj->title == '') $obj->title = 'Untitled';

		// unset document style if the user is not the document manager
		if(!$this->grant->manager)
		{
			unset($obj->title_color);
			unset($obj->title_bold);
		}

		// generate document module model object
		$oDocumentModel = getModel('document');

		// generate document module의 controller object
		$oDocumentController = getController('document');

		// check if the document is existed
		$oDocument = $oDocumentModel->getDocument($obj->document_srl, $this->grant->manager);

		// update the document if it is existed
		$is_update = false;
		if($oDocument->isExists() && $oDocument->document_srl == $obj->document_srl)
		{
			$is_update = true;
		}
		
		// if use anonymous is true
		if($this->module_info->use_anonymous == 'Y')
		{
			$this->module_info->admin_mail = '';
			$obj->notify_message = 'N';
			if($is_update===false)
			{
				$obj->member_srl = -1*$logged_info->member_srl;
			}
			$obj->email_address = $obj->homepage = $obj->user_id = '';
			$obj->user_name = $obj->nick_name = 'anonymous';
			$bAnonymous = true;
			if($is_update===false)
			{
				$oDocument->add('member_srl', $obj->member_srl);
			}
		}
		else
		{
			$bAnonymous = false;
		}

		if(($obj->is_secret == 'Y') || strtoupper($obj->status == 'SECRET'))
		{
			$use_status = explode('|@|', $this->module_info->use_status);
			if(!is_array($use_status) || !in_array('SECRET', $use_status))
			{
				unset($obj->is_secret);
				$obj->status = 'PUBLIC';
			}
		}

		// update the document if it is existed
		if($is_update)
		{
			$document_srl = Context::get('document_srl');
			$oBoardModel = getModel('board');
			$doc_type = $oBoardModel->getBoardDocumentType($document_srl);
     	       $oDocument->add('document_type', $doc_type->point);			
     	       if($doc_type->point == '1')
			{
			}
			if($doc_type->point == '0')
			{
				if(!$oDocument->isGranted())
				{
					return new Object(-1,'msg_not_permitted');
				}
			}

			if($this->module_info->protect_content=="Y" && $oDocument->get('comment_count')>0 && $this->grant->manager==false)
			{
				return new Object(-1,'msg_protect_content');
			}

			if(!$this->grant->manager)
			{
				// notice & document style same as before if not manager
				$obj->is_notice = $oDocument->get('is_notice');
				$obj->title_color = $oDocument->get('title_color');
				$obj->title_bold = $oDocument->get('title_bold');
			}
			
			// modify list_order if document status is temp
			if($oDocument->get('status') == 'TEMP')
			{
				$obj->last_update = $obj->regdate = date('YmdHis');
				$obj->update_order = $obj->list_order = (getNextSequence() * -1);
			}

                // 확장 필드에 값이 있으면 새로 셋팅
                $extra_vars = unserialize($oDocument->get('extra_vars'));
                if($extra_vars) $obj->extra_vars = $extra_vars;
			
                // 히스토리 사용중인지 체크
                $module_srl = $oDocument->get('module_srl');
                $oModuleModel = &getModel('module');
                $document_config = $oModuleModel->getModulePartConfig('document', $module_srl);
                if(!isset($document_config->use_history)) $document_config->use_history = 'N';
                $bUseHistory = $document_config->use_history == 'Y' || $document_config->use_history == 'Trace';

                // (히스토리) 익명 사용시 익명 정보 갱신
                // 문서 수정시 에러 발생으로 주석처리
                /*if($logged_info && ($bUseHistory || ($logged_info->member_srl == $oDocument->member_srl) && (($is_anonymous && $oDocument->member_srl > 0)||(!$is_anonymous && $oDocument->member_srl < 0)))){
                    $any_args = $this->_setUserInfo($is_anonymous, $logged_info);
                    $any_args->document_srl = $output->get('document_srl');
                    $anonymous_output = executeQuery('board.updateDocumentUserInfo', $any_args);
                    if(!$anonymous_output->toBool()) return $anonymous_output;
                }*/

			$output = $oDocumentController->updateDocument($oDocument, $obj);
			$msg_code = 'success_updated';

		// insert a new document otherwise
		} else {
			// check grant (공용문서에서 수정가능하기위해 이동)
			if(!$this->grant->write_document)
			{
				return $this->dispBoardMessage('msg_not_permitted');
			}
                
			// 모바일에서 작성시 정보 저장
			if($this->module_info->use_mobile_express && Mobile::isFromMobilePhone()){
				unset($extra_vars);
				$extra_vars->board->d->mp = true;
				// Document 모듈에서 serialize($extra_vars) 자동으로 함
				$obj->extra_vars = $extra_vars;
			}
                
			$output = $oDocumentController->insertDocument($obj, $bAnonymous);
			$msg_code = 'success_registed';
			$obj->document_srl = $output->get('document_srl');

  	  		// 문서타입기록 (새문서일때만)
			$this->insertDocumentType($obj);
			// send an email to admin user
			if($output->toBool() && $this->module_info->admin_mail)
			{
				$oMail = new Mail();
				$oMail->setTitle($obj->title);
				$oMail->setContent( sprintf("From : <a href=\"%s\">%s</a><br/>\r\n%s", getFullUrl('','document_srl',$obj->document_srl), getFullUrl('','document_srl',$obj->document_srl), $obj->content));
				$oMail->setSender($obj->user_name, $obj->email_address);

				$target_mail = explode(',',$this->module_info->admin_mail);
				for($i=0;$i<count($target_mail);$i++)
				{
					$email_address = trim($target_mail[$i]);
					if(!$email_address) continue;
					$oMail->setReceiptor($email_address, $email_address);
					$oMail->send();
				}
			}
		}

		// if there is an error
		if(!$output->toBool())
		{
			return $output;
		}

		// return the results
		$this->add('mid', Context::get('mid'));
		$this->add('document_srl', $output->get('document_srl'));
            
		// alert a message
		$this->setMessage($msg_code);
	}

	/**
	 * @brief delete the document
	 **/
	function procBoardDeleteDocument()
	{
		// get the document_srl
		$document_srl = Context::get('document_srl');

		// if the document is not existed
		if(!$document_srl)
		{
			return $this->doError('msg_invalid_document');
		}
             
            // 공용문서 사용시 관리자 외에 삭제불가
            $oBoardModel = getModel('board');
            $doc_type = $oBoardModel->getBoardDocumentType($document_srl);
            if(!$this->grant->manager && $doc_type->point == '1'){
			return new Object(-1, '공용문서는 삭제할 수 없습니다.');
            }
		$oDocumentModel = &getModel('document');
		$oDocument = $oDocumentModel->getDocument($document_srl);
		// check protect content
		if($this->module_info->protect_content=="Y" && $oDocument->get('comment_count')>0 && $this->grant->manager==false)
		{
			return new Object(-1, 'msg_protect_content');
		}
		// generate document module controller object
		$oDocumentController = getController('document');

		// delete the document
		$output = $oDocumentController->deleteDocument($document_srl, $this->grant->manager);
		if(!$output->toBool())
		{
			return $output;
		}

            $args->document_srl = $document_srl;
            // 문서타입삭제
            executeQuery('board.deleteDocumentType', $args);

		// alert an message
		$this->add('mid', Context::get('mid'));
		$this->add('page', $output->get('page'));
		$this->setMessage('success_deleted');
	}

	/**
	 * @brief vote
	 **/
	function procBoardVoteDocument()
	{
		// generate document module controller object
		$oDocumentController = getController('document');

		$document_srl = Context::get('document_srl');
		return $oDocumentController->updateVotedCount($document_srl);
	}

	/**
	 * @brief insert comments
	 **/
	function procBoardInsertComment()
	{
		// check grant
		if(!$this->grant->write_comment)
		{
			return new Object(-1, 'msg_not_permitted');
		}
		$logged_info = Context::get('logged_info');

		// get the relevant data for inserting comment
		$obj = Context::getRequestVars();
		$obj->module_srl = $this->module_srl;

		if(!$this->module_info->use_status) $this->module_info->use_status = 'PUBLIC';
		if(!is_array($this->module_info->use_status))
		{
			$this->module_info->use_status = explode('|@|', $this->module_info->use_status);
		}

		if(in_array('SECRET', $this->module_info->use_status))
		{
			$this->module_info->secret = 'Y';
		}
		else
		{
			unset($obj->is_secret);
			$this->module_info->secret = 'N';
		}

		// check if the doument is existed
		$oDocumentModel = getModel('document');
		$oDocument = $oDocumentModel->getDocument($obj->document_srl);
		if(!$oDocument->isExists())
		{
			return new Object(-1,'msg_not_founded');
		}

		// For anonymous use, remove writer's information and notifying information
		if($this->module_info->use_anonymous == 'Y')
		{
			$this->module_info->admin_mail = '';
			$obj->notify_message = 'N';
			$obj->member_srl = -1*$logged_info->member_srl;
			$obj->email_address = $obj->homepage = $obj->user_id = '';
			$obj->user_name = $obj->nick_name = 'anonymous';
			$bAnonymous = true;
		}
		else
		{
			$bAnonymous = false;
		}

		// generate comment  module model object
		$oCommentModel = getModel('comment');

		// generate comment module controller object
		$oCommentController = getController('comment');

		// check the comment is existed
		// if the comment is not existed, then generate a new sequence
		if(!$obj->comment_srl)
		{
			$obj->comment_srl = getNextSequence();
		} else {
			$comment = $oCommentModel->getComment($obj->comment_srl, $this->grant->manager);
		}

		// if comment_srl is not existed, then insert the comment
		if($comment->comment_srl != $obj->comment_srl)
		{

			// parent_srl is existed
			if($obj->parent_srl)
			{
				$parent_comment = $oCommentModel->getComment($obj->parent_srl);
				if(!$parent_comment->comment_srl)
				{
					return new Object(-1, 'msg_invalid_request');
				}

				$output = $oCommentController->insertComment($obj, $bAnonymous);

			// parent_srl is not existed
			} else {
				$output = $oCommentController->insertComment($obj, $bAnonymous);
			}
			
                // 모바일에서 작성시 정보 저장
                if($this->module_info->use_mobile_express && Mobile::isFromMobilePhone()){
                    $extra_vars = unserialize($oDocument->get('extra_vars'));
                    $extra_vars->board->c[$obj->comment_srl]->mp = true;
                    $extra_args->document_srl = $obj->document_srl;
                    $extra_args->extra_vars = serialize($extra_vars);
                    $tmp_output = executeQuery('board.updateDocumentExtra', $extra_args);
                }
                
		// update the comment if it is not existed
		} else {
			// check the grant
			if(!$comment->isGranted())
			{
				return new Object(-1,'msg_not_permitted');
			}

			$obj->parent_srl = $comment->parent_srl;
			$output = $oCommentController->updateComment($obj, $this->grant->manager);
			$comment_srl = $obj->comment_srl;
		}

		if(!$output->toBool())
		{
			return $output;
		}

		$this->setMessage('success_registed');
		$this->add('mid', Context::get('mid'));
		$this->add('document_srl', $obj->document_srl);
		$this->add('comment_srl', $obj->comment_srl);
	}

	/**
	 * @brief delete the comment
	 **/
	function procBoardDeleteComment()
	{
		// get the comment_srl
		$comment_srl = Context::get('comment_srl');
		if(!$comment_srl)
		{
			return $this->doError('msg_invalid_request');
		}

		// generate comment  controller object
		$oCommentController = getController('comment');

		$output = $oCommentController->deleteComment($comment_srl, $this->grant->manager);
		if(!$output->toBool())
		{
			return $output;
		}

            // 확장변수에 값이 있으면 삭제 (모바일 정보)
            if($this->module_info->use_mobile_express){
                $oDocumentModel = &getModel('document');
                $oDocument = $oDocumentModel->getDocument($document_srl, false, false);
                if($oDocument->isExists()){
                    $extra_vars = unserialize($oDocument->get('extra_vars'));
                    if($extra_vars->board->c[$comment_srl]){
                        unset($extra_vars->board->c[$comment_srl]);
                        $extra_args->document_srl = $document_srl;
                        $extra_args->extra_vars = serialize($extra_vars);
                        $tmp_output = executeQuery('board.updateDocumentExtra', $extra_args);
                    }
                }
            }
		$this->add('mid', Context::get('mid'));
		$this->add('page', Context::get('page'));
		$this->add('document_srl', $output->get('document_srl'));
		$this->setMessage('success_deleted');
	}

	/**
	 * @brief delete the tracjback
	 **/
	function procBoardDeleteTrackback()
	{
		$trackback_srl = Context::get('trackback_srl');

		// generate trackback module controller object
		$oTrackbackController = getController('trackback');

		if(!$oTrackbackController) return;

		$output = $oTrackbackController->deleteTrackback($trackback_srl, $this->grant->manager);
		if(!$output->toBool())
		{
			return $output;
		}

		$this->add('mid', Context::get('mid'));
		$this->add('page', Context::get('page'));
		$this->add('document_srl', $output->get('document_srl'));
		$this->setMessage('success_deleted');
	}

	/**
	 * @brief check the password for document and comment
	 **/
	function procBoardVerificationPassword()
	{
		// get the id number of the document and the comment
		$password = Context::get('password');
		$document_srl = Context::get('document_srl');
		$comment_srl = Context::get('comment_srl');

		$oMemberModel = getModel('member');

		// if the comment exists
		if($comment_srl)
		{
			// get the comment information
			$oCommentModel = getModel('comment');
			$oComment = $oCommentModel->getComment($comment_srl);
			if(!$oComment->isExists())
			{
				return new Object(-1, 'msg_invalid_request');
			}

			// compare the comment password and the user input password
			if(!$oMemberModel->isValidPassword($oComment->get('password'),$password))
			{
				return new Object(-1, 'msg_invalid_password');
			}

			$oComment->setGrant();
		} else {
			 // get the document information
			$oDocumentModel = getModel('document');
			$oDocument = $oDocumentModel->getDocument($document_srl);
			if(!$oDocument->isExists())
			{
				return new Object(-1, 'msg_invalid_request');
			}

			// compare the document password and the user input password
			if(!$oMemberModel->isValidPassword($oDocument->get('password'),$password))
			{
				return new Object(-1, 'msg_invalid_password');
			}

			$oDocument->setGrant();
		}
	}

	/**
	 * @brief the trigger for displaying 'view document' link when click the user ID
	 **/
	function triggerMemberMenu(&$obj)
	{
		$member_srl = Context::get('target_srl');
		$mid = Context::get('cur_mid');

		if(!$member_srl || !$mid)
		{
			return new Object();
		}

		$logged_info = Context::get('logged_info');

		// get the module information
		$oModuleModel = getModel('module');
		$columnList = array('module');
		$cur_module_info = $oModuleModel->getModuleInfoByMid($mid, 0, $columnList);

		if($cur_module_info->module != 'board')
		{
			return new Object();
		}

		// get the member information
		if($member_srl == $logged_info->member_srl)
		{
			$member_info = $logged_info;
		} else {
			$oMemberModel = getModel('member');
			$member_info = $oMemberModel->getMemberInfoByMemberSrl($member_srl);
		}

		if(!$member_info->user_id)
		{
			return new Object();
		}

		//search
		$url = getUrl('','mid',$mid,'search_target','nick_name','search_keyword',$member_info->nick_name);
		$oMemberController = getController('member');
		$oMemberController->addMemberPopupMenu($url, 'cmd_view_own_document', '');

		return new Object();
	}

        
	// 문서타입
        function insertDocumentType(&$obj) {
            $logged_info = Context::get('logged_info');
            $args->document_srl = $obj->document_srl;
            $args->member_srl = $logged_info->member_srl;
            $args->ipaddress = $_SERVER['REMOTE_ADDR'];
            $args->point = $obj->document_type;
            //$queryid = 'board.deleteDocumentType';
            //$output = executeQuery($queryid, $args);
            $queryid = 'board.insertDocumentType';
            $output = executeQuery($queryid, $args);
            return $output;
        }
        /* 위키 컨텐츠 차이보기 */
	function procBoardContentDiff() 
	{
		$document_srl = Context::get("document_srl"); 
		$history_srl = Context::get("history_srl"); 
		$oDocumentModel = &getModel('document'); 
		$oDocument = $oDocumentModel->getDocument($document_srl); 
		$current_content = $oDocument->get('content');
		$history = $oDocumentModel->getHistory($history_srl);
		$history_content = $history->content; 
		$this->add('old', $history_content); 
		$this->add('current', $current_content);
	}
	/* 유저정보 (히스토리에 유저정보 기록) */
        function _setUserInfo($is_any, $sor_obj, $out_obj=null){
            // sor_obj 에 유저 정보가 없다면 기본값 리턴
            if(!$sor_obj || !$sor_obj->member_srl) return $out_obj;

            if(!$is_any){
                $out_obj->member_srl = $sor_obj->member_srl;
                $out_obj->email_address = $sor_obj->email_address;
                $out_obj->homepage = $sor_obj->homepage;
                $out_obj->user_id = $sor_obj->user_id;
                $out_obj->user_name = $sor_obj->user_name;
                $out_obj->nick_name = $sor_obj->nick_name;

            }else{
                $out_obj->user_id = '';
                $member_srl = $sor_obj->member_srl;

                // 보안 단계별 설정 (상담 사용시 1단계만)
                //if((string)$this->module_info->use_anonymous_phase=='1'||$this->module_info->consultation == 'Y'){
                //    $out_obj->member_srl = abs($member_srl);
                //    $out_obj->user_id = 'anonymous';
                //}elseif((string)$this->module_info->use_anonymous_phase=='3')
                    $out_obj->member_srl = 0;
                //else $out_obj->member_srl = -1 * abs($member_srl);

                $out_obj->email_address = $out_obj->homepage = '';
                $out_obj->user_name = $out_obj->nick_name = 'anonymous';
            }

            return $out_obj;
        }
        
        /**
        * @brief 문서의 상태 변경
        **/
        function procBoardChangeState(){
            if(!Context::get('is_logged')) return new Object(-1, 'msg_not_permitted_act');

            $logged_info = Context::get('logged_info');
            $grant = $this->_getGrant(Context::get('cur_mid'), $logged_info);

            // 관리자가 아니면 중단
            if($logged_info->is_admin != 'Y' && !$grant->manager) return new Object(-1, 'msg_not_permitted_act');

            $target_srls = explode(',',Context::get('target_srls'));
            if(!count($target_srls)) return false;

            // 상태값 유효성 체크
            $state_value = Context::get('state_value');
            if(!is_numeric($state_value)||($state_value>9&&$state_value<0)) return false;

            $args->is_notice = $state_value?$state_value:'N';

            // 문서 보기에서 변경한게 아니면 관리자 메뉴와 혼란을 피하기 위해 세션 삭제
            if(!Context::get('document_srl')) unset($_SESSION['document_management']);

             foreach($target_srls as $val){
                $args->document_srl = $val;
                $args->last_updater = $logged_info->nick_name.'('.Context::getLang('doc_state').')';
                $output = executeQuery('board.updateDocumentState', $args);
            }

            // 결과 리턴
            return new Object(0, 'success_updated');
        }
        
        function procBoardHistoryRestore(){
            if(!Context::get('is_logged')) return new Object(-1, 'msg_invalid_request');

            // 문서번호가 없으면 에러
            $history_srl = Context::get('target_srl');
            if(!$history_srl) return new Object(-1, 'msg_invalid_request');

            $oDocumentModel = &getModel('document');
            $history_data = $oDocumentModel->getHistory($history_srl);
            if(!$history_data->history_srl) return new Object(-1, 'msg_not_founded');

            $logged_info = Context::get('logged_info');
            $grant = $this->_getGrant(Context::get('cur_mid'), $logged_info);

            // 관리자와 로그인유저가 아니면 오류
            if($logged_info->is_admin != 'Y' && !$grant->manager && (!Context::get('is_logged'))) return new Object(-1, 'msg_not_permitted_act');

            // 이미 존재하는 글인지 체크
            $oDocument = $oDocumentModel->getDocument($history_data->document_srl, $this->grant->manager, false);
            if(!$oDocument->isExists()) return new Object(-1, 'msg_not_founded');

            $module_srl = $oDocument->get('module_srl');
            $oModuleModel = &getModel('module');
            $document_config = $oModuleModel->getModulePartConfig('document', $module_srl);
            if(!isset($document_config->use_history)) $document_config->use_history = 'N';
            $bUseHistory = $document_config->use_history == 'Y' || $document_config->use_history == 'Trace';

            if(!$bUseHistory) return new Object(-1, 'msg_not_use_history');

            // 같은 히스토리 문서 존재하는지 체크
            $args->regdate = $oDocument->get('last_update');
            $output = executeQuery('board.checkDocumentHistory', $args);

            // 같은 히스토리 문서가 없고 히스토리 기능 사용중이면 새로 기록
            if(!$output->data->count && $bUseHistory){
                $hArgs->history_srl = getNextSequence();
                $hArgs->module_srl = $module_srl;
                $hArgs->document_srl = $oDocument->get('document_srl');
                if($document_config->use_history == 'Y') $hArgs->content = $oDocument->get('content');
                $hArgs->nick_name = $oDocument->get('nick_name');
                $hArgs->member_srl = $oDocument->get('member_srl');
                $hArgs->regdate = $oDocument->get('last_update');
                $hArgs->ipaddress = $oDocument->get('ipaddress');
                $output = executeQuery('document.insertHistory', $hArgs);
            }

            $oDocumentController = &getController('document');

            // 맴버정보 구함
            $oMemberModel = &getModel('member');
            $member_info = $oMemberModel->getMemberInfoByMemberSrl($history_data->member_srl, $this->module_info->site_srl);

            // 맴버가 있고 익명이 아니면 유저정보 입력
            if($member_info && $history_data->member_srl > 0)
                $obj = $this->_setUserInfo(false, $member_info);
            else{
                $obj->member_srl = $history_data->member_srl;
                $obj->email_address = $obj->homepage = $obj->user_id = '';
                $obj->user_name = $obj->nick_name = $history_data->nick_name;
            }

            $obj->document_srl = $history_data->document_srl;
            $obj->last_update = $history_data->regdate;
            $obj->content = $history_data->content;
            $obj->ipaddress = $history_data->ipaddress;
            $output = executeQuery('board.restoreDocumentHistory', $obj);
            if(!$output->toBool()) return $output;

            // 썸네일 파일 제거
            FileHandler::removeDir(sprintf('files/cache/thumbnails/%s',getNumberingPath($obj->document_srl, 3)));

             return new Object(0, 'success_restore');
        }
        function procBoardHistoryDelete(){
            if(!Context::get('is_logged')) return new Object(-1, 'msg_not_permitted_act');

            $logged_info = Context::get('logged_info');
            $grant = $this->_getGrant(Context::get('cur_mid'), $logged_info);

            // 관리자가 아니면 중단
            if($logged_info->is_admin != 'Y' && !$grant->manager) return new Object(-1, 'msg_not_permitted_act');

            // 문서번호가 없으면 에러
            $history_srl = Context::get('target_srl');
            if(!$history_srl) return new Object(-1, 'msg_invalid_request');

            $oDocumentModel = &getModel('document');
            $history_data = $oDocumentModel->getHistory($history_srl);
            if(!$history_data->history_srl) return new Object(-1, 'msg_not_founded');

            // 이미 존재하는 글인지 체크
            $oDocument = $oDocumentModel->getDocument($history_data->document_srl, $grant->manager, false);
            if(!$oDocument->isExists()) return new Object(-1, 'msg_not_founded');

            $module_srl = $oDocument->get('module_srl');
            $oModuleModel = &getModel('module');
            $document_config = $oModuleModel->getModulePartConfig('document', $module_srl);
            if(!isset($document_config->use_history)) $document_config->use_history = 'N';
            $bUseHistory = $document_config->use_history == 'Y' || $document_config->use_history == 'Trace';

            if(!$bUseHistory) return new Object(-1, 'msg_not_use_history');

            $args->history_srl = $history_data->history_srl;
            $args->module_srl = $history_data->module_srl;
            $args->document_srl = $history_data->document_srl;
            $output = executeQuery('document.deleteHistory', $args);

            if(!$output->toBool()) return $output;

             return new Object(0, 'success_deleted');
        }
        function _getGrant($cur_mid, $logged_info){
            if(!$cur_mid || !$logged_info) return;

            $oModuleModel = &getModel('module');
            $cur_module_info = $oModuleModel->getModuleInfoByMid($cur_mid);
            return $oModuleModel->getGrant($cur_module_info, $logged_info);
        }
}
