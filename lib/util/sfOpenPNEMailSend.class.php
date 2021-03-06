<?php

/**
 * This file is part of the OpenPNE package.
 * (c) OpenPNE Project (http://www.openpne.jp/)
 *
 * For the full copyright and license information, please view the LICENSE
 * file and the NOTICE file that were distributed with this source code.
 */

/**
 * sfOpenPNEMailSend
 *
 * @package    OpenPNE
 * @subpackage util
 * @author     Kousuke Ebihara <ebihara@tejimaya.com>
 */
class sfOpenPNEMailSend
{
  public $subject = '';
  public $body = '';
  static protected $initialized = false;

  public static function initialize()
  {
    if (!self::$initialized)
    {
      sfOpenPNEApplicationConfiguration::registerZend();

      if ($host = sfConfig::get('op_mail_smtp_host'))
      {
        $tr = new Zend_Mail_Transport_Smtp($host, sfConfig::get('op_mail_smtp_config', array()));
        Zend_Mail::setDefaultTransport($tr);
      }
      elseif ($envelopeFrom = sfConfig::get('op_mail_envelope_from'))
      {
        $tr = new Zend_Mail_Transport_Sendmail('-f'.$envelopeFrom);
        Zend_Mail::setDefaultTransport($tr);
      }

      sfOpenPNEApplicationConfiguration::unregisterZend();

      self::$initialized = true;
    }
  }

  public function setSubject($subject)
  {
    $this->subject = $subject;
  }

  public function setTemplate($template, $params = array())
  {
    $body = $this->getCurrentAction()->getPartial($template, $params);
    $this->body = $body;
  }

  public function setGlobalTemplate($template, $params = array())
  {
    $template = '_'.$template;
    $view = new opGlobalPartialView(sfContext::getInstance(), 'superGlobal', $template, '');
    $view->setPartialVars($params);
    $body = $view->render();
    $this->body = $body;
  }

  public function send($to, $from)
  {
    return self::execute($this->subject, $to, $from, $this->body);
  }

  public static function getMailTemplate($template, $target = 'pc', $params = array(), $isOptional = true, $context = null)
  {
    if (!$context)
    {
      $context = sfContext::getInstance();
    }

    $params['sf_config'] = sfConfig::getAll();

    $escapingMethod = sfConfig::get('sf_escaping_method');
    $escapingStrategy = sfConfig::get('sf_escaping_strategy');
    sfConfig::set('sf_escaping_method', 'off');
    sfConfig::set('sf_escaping_strategy', 'off');

    $view = new sfTemplatingComponentPartialView($context, 'superGlobal', 'notify_mail:'.$target.'_'.$template, '');

    sfConfig::set('sf_escaping_method', $escapingMethod);
    sfConfig::set('sf_escaping_strategy', $escapingStrategy);

    $context->set('view_instance', $view);

    $view->setPartialVars($params);
    $view->setAttribute('renderer_config', array('twig' => 'opTemplateRendererTwig'));
    $view->setAttribute('rule_config', array('notify_mail' => array(
        array('loader' => 'sfTemplateSwitchableLoaderDoctrine', 'renderer' => 'twig', 'model' => 'NotificationMail'),
        array('loader' => 'opNotificationMailTemplateLoaderFilesystem', 'renderer' => 'php'),
    )));
    $view->execute();

    try
    {
      return $view->render();
    }
    catch (InvalidArgumentException $e)
    {
      if ($isOptional)
      {
        return '';
      }

      throw $e;
    }
  }

  public static function sendTemplateMail($template, $to, $from, $params = array(), $context = null)
  {
    if (!$to)
    {
      return false;
    }

    if (empty($params['target']))
    {
      $target = opToolkit::isMobileEmailAddress($to) ? 'mobile' : 'pc';
    }
    else
    {
      $target = $params['target'];
    }

    if (in_array($target.'_'.$template, Doctrine::getTable('NotificationMail')->getDisabledNotificationNames()))
    {
      return false;
    }

    $body = self::getMailTemplate($template, $target, $params, false, $context);
    $signature = self::getMailTemplate('signature', $target, array(), true, $context);
    if ($signature)
    {
      $signature = "\n".$signature;
    }

    return self::execute($params['subject'], $to, $from, $body.$signature);
  }

  public static function execute($subject, $to, $from, $body)
  {
    if (!$to)
    {
      return false;
    }

    self::initialize();

    sfOpenPNEApplicationConfiguration::registerZend();

    $subject = mb_convert_kana($subject, 'KV');

    $mailer = new Zend_Mail('iso-2022-jp');
    $mailer->setHeaderEncoding(Zend_Mime::ENCODING_BASE64)
      ->setFrom($from)
      ->addTo($to)
      ->setSubject(mb_encode_mimeheader($subject, 'iso-2022-jp'))
      ->setBodyText(mb_convert_encoding($body, 'JIS', 'UTF-8'), 'iso-2022-jp', Zend_Mime::ENCODING_7BIT);

    if ($envelopeFrom = sfConfig::get('op_mail_envelope_from'))
    {
      $mailer->setReturnPath($envelopeFrom);
    }

    $result = $mailer->send();

    Zend_Loader::registerAutoLoad('Zend_Loader', false);

    return $result;
  }

 /**
  * Gets the current action instance.
  *
  * @return sfAction
  */
  protected function getCurrentAction()
  {
    return sfContext::getInstance()->getController()->getActionStack()->getLastEntry()->getActionInstance();
  }
}
