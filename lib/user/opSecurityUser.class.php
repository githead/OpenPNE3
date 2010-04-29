<?php

/**
 * This file is part of the OpenPNE package.
 * (c) OpenPNE Project (http://www.openpne.jp/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file and the NOTICE file that were distributed with this source code.
 */

/**
 * opSecurityUser will handle credential for OpenPNE.
 *
 * @package    OpenPNE
 * @subpackage user
 * @author     Kousuke Ebihara <ebihara@php.net>
 * @author     Shogo Kawahara <kawahara@tejimaya.com>
 */
class opSecurityUser extends opAdaptableUser
{
  protected
    $authAdapters = array(),
    $serializedMember = '';

  /**
   * Initializes the current user.
   *
   * @see sfBasicSecurityUser
   */
  public function initialize(sfEventDispatcher $dispatcher, sfStorage $storage, $options = array())
  {
    parent::initialize($dispatcher, $storage, $options);

    $this->initializeCredentials();
  }

  public function getMemberId()
  {
    return $this->getAttribute('member_id', null, 'opSecurityUser');
  }

  public function setMemberId($memberId)
  {
    return $this->setAttribute('member_id', $memberId, 'opSecurityUser');
  }

  public function getMember($inactive = false)
  {
    if (!$this->getMemberId())
    {
      return new opAnonymousMember();
    }

    if ($inactive)
    {
      return Doctrine::getTable('Member')->findInactive($this->getMemberId());
    }

    if ($this->serializedMember)
    {
      return unserialize($this->serializedMember);
    }

    $result = Doctrine::getTable('Member')->find($this->getMemberId());

    $this->serializedMember = serialize($result);

    return $result;
  }

  public function getCurrentMemberRegisterToken()
  {
    opActivateBehavior::disable();
    $config = Doctrine::getTable('MemberConfig')->retrieveByNameAndMemberId('register_token', $this->getMemberId(), true);
    opActivateBehavior::enable();

    if ($config)
    {
      return $config->getValue();
    }

    return null;
  }

  public function getRegisterInputAction($token = null)
  {
    if (!$token)
    {
      $token = $this->getCurrentMemberRegisterToken();
    }

    return $this->getAuthAdapter()->getRegisterInputAction($token);
  }

  public function getRegisterEndAction($token = null)
  {
    if (!$token)
    {
      $token = $this->getCurrentMemberRegisterToken();
    }

    return $this->getAuthAdapter()->getRegisterEndAction($token);
  }

 /**
  * get remember login cookie
  *
  * @return array
  */
  protected function getRememberLoginCookie()
  {
    $key = md5(sfContext::getInstance()->getRequest()->getHost());
    if ($value = sfContext::getInstance()->getRequest()->getCookie($key))
    {
      $value = unserialize(base64_decode($value));

      return $value;
    }
  }

 /**
  * set remember login cookie
  */
  protected function setRememberLoginCookie($isDeleteCookie = false)
  {
    $key = md5(sfContext::getInstance()->getRequest()->getHost());
    $path = sfContext::getInstance()->getRequest()->getRelativeUrlRoot();
    if (!$path)
    {
      $path = '/';
    }

    if ($isDeleteCookie)
    {
      if (!sfContext::getInstance()->getRequest()->getCookie($key))
      {
        return;
      }

      if ($this->getMemberId())
      {
        $this->getMember()->setConfig('remember_key', '');
      }

      $value = null;
      $expire = time() - 3600;
    }
    else
    {
      $rememberKey = opToolkit::generatePasswordString();
      if (!$this->getMemberId())
      {
        throw new LogicException('No login');
      }
      $this->getMember()->setConfig('remember_key', $rememberKey);

      $value = base64_encode(serialize(array($this->getMemberId(), $rememberKey)));
      $expire = time() + sfConfig::get('op_remember_login_limit', 60*60*24*30);
    }

    sfContext::getInstance()->getResponse()->setCookie($key, $value, $expire, $path, '', false, true);
  }

 /**
  * get memberd member id
  *
  * @return integer the member id
  */
  public function getRememberedMemberId()
  {
    if (($value = $this->getRememberLoginCookie()) && 2 == count($value))
    {
      if ($value[0] && $value[1])
      {
        $memberConfig = Doctrine::getTable('MemberConfig')->findOneByMemberIdAndNameAndValue($value[0], 'remember_key', $value[1]);
        if ($memberConfig)
        {
          $expire = strtotime($memberConfig->getUpdatedAt()) + sfConfig::get('op_remember_login_limit', 60*60*24*30);
          if ($expire > time())
          {
            return $value[0];
          }
        }
      }
    }
  }

 /**
  * Login
  *
  * @param integer $memberId the member id
  * @return bool   returns true if the current user is authenticated, false otherwise
  */
  public function login($memberId = null)
  {
    if (null === $memberId)
    {
      $memberId = $this->getAuthAdapter()->authenticate();
    }

    if ($memberId)
    {
      $this->setMemberId($memberId);

      opActivateBehavior::disable();
      if ($this->getMember()->isOnBlacklist())
      {
        opActivateBehavior::enable();
        $this->logout();

        return false;
      }
      opActivateBehavior::enable();

      $this->setAuthenticated(true);
    }

    $this->initializeCredentials();

    if ($this->getAuthAdapter()->getAuthForm()->getValue('is_remember_me'))
    {
      $this->setRememberLoginCookie();
    }

    if ($this->isAuthenticated())
    {
      $this->setCurrentAuthMode($this->getAuthAdapter()->getAuthModeName());
      $uri = $this->getAuthAdapter()->getAuthForm()->getValue('next_uri');

      return $uri;
    }

    return false;
  }

 /**
  * Logout
  */
  public function logout()
  {
    $authMode = $this->getCurrentAuthMode();

    $this->setRememberLoginCookie(true);

    $this->setAuthenticated(false);
    $this->getAttributeHolder()->removeNamespace('opSecurityUser');
    $this->clearCredentials();

    $this->setCurrentAuthMode($authMode);
  }

 /**
  * Registers the current user with OpenPNE
  *
  * @param  sfForm $form
  * @return bool   returns true if the current user is authenticated, false otherwise
  */
  public function register($form = null)
  {
    $result = $this->getAuthAdapter()->register($form);
    if ($result)
    {
      $this->setAuthenticated(true);
      $this->setAttribute('member_id', $result, 'opSecurityUser');

      return true;
    }

    return false;
  }

 /**
  * Initializes all credentials associated with this user.
  */
  public function initializeCredentials()
  {
    $memberId = $this->getMemberId();

    // for BC
    $this->setIsSNSMember($this->isSNSMember());
  }

  public function isMember()
  {
    return $this->isSNSMember();
  }

  public function isSNSMember()
  {
    return ($this->getMember() && $this->getMember()->getIsActive());
  }

  public function isRegisterBegin()
  {
    return $this->getAuthAdapter()->isRegisterBegin($this->getMemberId());
  }

  public function isRegisterFinish()
  {
    return $this->getAuthAdapter()->isRegisterFinish($this->getMemberId());
  }

  public function setIsSNSMember($isSNSMember)
  {
    if ($isSNSMember)
    {
      $this->setAuthenticated(true);
      $this->addCredential('SNSMember');
    }
    else
    {
      $this->removeCredential('SNSMember');
    }
  }

  public function setIsSNSRegisterBegin($isSNSRegisterBegin)
  {
  }

  public function setIsSNSRegisterFinish($isSNSRegisterFinish)
  {
  }

  public function setRegisterToken($token)
  {
    $member = Doctrine::getTable('Member')->findByRegisterToken($token);
    if (!$member)
    {
      return false;
    }

    $this->setMemberId($member->getId());

    return true;
  }
}
