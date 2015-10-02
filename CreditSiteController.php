<?php

namespace Sbps\Bundle\KcSsoBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Sbps\Bundle\MemberWebBundle\Controller\BaseController;
use Sbps\SystemBridge\Entity\KC;
use Sbps\Bundle\SecurityBundle\Component\Manager\SecurityManager;

class CreditSiteController extends BaseController
{
    /**
     * KC-SSO
     * @param Request $request
     * @param unknown $path
     * @return \Symfony\Component\HttpFoundation\RedirectResponse


     **/
/*
test用
*/

public public function test()
{
    # code...
}




     
    public function indexAction(Request $request, $path)
    {
        $logger = $this->get('logger');
        
        // OTP認証対応
        $manager = $this->get('sbps_security.security_manager');
        $manager->removeEachtimeCredential();
        
        // WebIdを取得する
        $webId = $this->getWebId($request);
        $logger->debug('kcsso::webId:'.$webId);
        
        // KC-SSOよりセッションIDを取得する
        $kcSession = $this->getKcSessionId($request, $webId);
        $logger->debug('kcsso::kcSession:'.$kcSession);
        
        // KCログインURLを取得
        $url = $this->getKcSiteUrl($request, $path, $kcSession);
        if (($adid = $request->get('adid')) != null) {
            $url .= '?adid='.$adid;
        }
        $logger->debug('kcsso::url:'.$url);
        
        $response = $this->getResponse($request, $url);
        $logger->debug('kcsso::response:'.$response);
        
        return $response;
    }
    
    /**
     * WebIDを取得する
     * @throws \RuntimeException
     */
    protected function getWebId(Request $request)
    {
        // ログイン情報取得
        $token = $this->get('security.context')->getToken();
        $member = $token->getUser();
        
        // ID-Hubに問い合わせ
        $systemBridgeManager = $this->container->get('sbps_system_bridge.manager')->setEntities(array(
            'status_detail' => $this->container->get('sbps_system_bridge.entity.idhub.status_detail')->setOptions(array(
                'account_no' => $member->getAccountNo(),
            )),
        ));
        $systemBridgeManager->execute();
        
        $statusDetail = $systemBridgeManager->getEntity('status_detail');
        if ($statusDetail->isValid() !== true) {
            throw new \RuntimeException('api response is invalid.');
        }
        
        return $statusDetail->getCreditWebId();
    }
    
    /**
     * KC-SSOよりセッションIDを取得する
     * @param unknown $webId
     */
    protected function getKcSessionId(Request $request, $webId)
    {
        // KCに問い合わせ
        $systemBridgeManager = $this->container->get('sbps_system_bridge.manager')->setEntities(array(
            'sso' => $this->container->get('sbps_system_bridge.entity.kc.sso_request')->setOptions(array(
                'credit_web_id' => $webId,
                'medium_kind' => $this->getKcApiMediumKind($request),
            )),
        ));
        $systemBridgeManager->execute();
        
        $sso = $systemBridgeManager->getEntity('sso');
        if ($sso->isValid() !== true) {
            throw new \RuntimeException('api response is invalid.');
        }
        
        return $sso->getSessionId();
    }
    
    /**
     * KC APIに設定する媒体種類を取得する
     * @return number
     */
    protected function getKcApiMediumKind(Request $request)
    {
        return KC::MEDIUM_KIND_PC;
    }
    
    /**
     * KC サイト SSO 遷移先を取得する
     * @param unknown $path
     * @return string
     */
    protected function getKcSiteUrl(Request $request, $path, $kcSession)
    {
        $kcSiteLogin = $this->getKcSiteBaseUrl($request);
        $kcSiteLogin = $this->get('security.http_utils')->generateUri($request, $kcSiteLogin);
        
        $url = $kcSiteLogin.'/'.$path.'/'.$kcSession;
        $url = preg_replace('@([^:]|^)/{2,}@', '${1}/', $url);
        
        return $url;
    }
    
    /**
     * KC サイト SSO 遷移先 の Base URL を取得する
     * @param Request $request
     */
    protected function getKcSiteBaseUrl(Request $request)
    {
        return $this->container->getParameter('sbps_kc_sso.login');
    }
    
    /**
     * レスポンスを取得する
     * @param Request $request
     * @param unknown $url
     */
    protected function getResponse(Request $request, $url)
    {
        return $this->redirect($url, $this->container->getParameter('sbps_kc_sso.http_redirect_status_code'));
    }
    
    /**
     * OTPを挟んでKC-SSOを行う
     * @param Request $request
     */
    public function otpAction(Request $request, $path, $back)
    {
        $logger = $this->get('logger');
        
        $requireEachTime = false; // 都度認証の場合true
        $url = $this->getOtpRouting(); // 遷移先URL
        $cancelurl = $this->getOtpCancelRouting($back); // キャンセル時のURL
        
        // OTP要求
        $manager = $this->get('sbps_security.security_manager');
        if ($manager->hasCredential( SecurityManager::LV_SECOND, $requireEachTime) !== true) {
            $logger->debug('kcsso::onetime_password send');
            return $manager->createForwardResponse($this, $url, $cancelurl, false, array('path' => $path));
        }
        
        $action = $this->getOtpForward();
        $logger->debug('kcsso::forward:'.$action);
        return $this->forward($action, array('path' => $path));
    }
    
    /**
     * OTP成功時のルーティング
     */
    protected function getOtpRouting()
    {
        // sbps_member_web_kc_sso_alias
        return 'kc_sso_alias';
    }
    
    /**
     * OTPキャンセル時のルーティング
     * @return string
     */
    protected function getOtpCancelRouting($back)
    {
        $back = preg_replace('/^'.preg_quote(static::ABSOLUTE_ROUTE_PREFIX).'('.preg_quote(static::ABSOLUTE_ROUTE_DELIMITER).')?/', '', $back);
        return $back;
    }
    
    /**
     * OTP認証済み後のフォワード先
     * @return string
     */
    protected function getOtpForward()
    {
        return 'SbpsKcSsoBundle:CreditSite:index';
    }
}
