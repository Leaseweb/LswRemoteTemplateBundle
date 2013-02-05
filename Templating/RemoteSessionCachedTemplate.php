<?php
namespace Lsw\RemoteTemplateBundle\Templating;

use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Lsw\ApiCallerBundle\Caller\ApiCallerInterface;
use Lsw\ApiCallerBundle\Call\HttpGetHtml;

/**
* The RemoteSessionCachedTemplate loads a template from an URL using an API call and caches it in the session
*
* @author Maurits van der Schee <m.vanderschee@leaseweb.com>
*/
class RemoteSessionCachedTemplate
{
  // holds the session and api caller references
  protected $session;
  protected $apiCaller;
  // holds the config parameters
  protected $activationExpression;
  protected $replacementComment;
  protected $cacheTimeInSeconds;
  
  public function __construct($config, Session $session, ApiCallerInterface $apiCaller)
  { // store the session and api caller references in this object
    $this->session = $session;
    $this->apiCaller = $apiCaller;
    // convert the 'activation_comment' config parameter in a regex for remote url's
    $this->activationExpression = sprintf('/'.preg_quote($config['activation_comment']).'/','((http|\/)[^ ]+)');
    // load the other two config parameters
    $this->replacementComment = $config['replacement_comment'];
    $this->cacheTimeInSeconds = 10;
  }
  
  public function onKernelResponse(FilterResponseEvent $event)
  { // get the request from the event
    $request = $event->getRequest();
    // if the request is an ajax call, do not waste time and return
    if ($request->isXmlHttpRequest()) return;
    // get the response from the event
    $response = $event->getResponse();
    // get the htmlthat the response wants to render
    $html = $response->getContent();
    // if it does not contain the activation expression return (do nothing)  
    if (!preg_match($this->activationExpression,$html,$matches)) return;
    // get the mathed url  
    $url=$matches[1];
    // if the url does not contain scheme and host, add them  
    if ($matches[2]=='/') $url = $request->getScheme().'://'.$request->getHttpHost().$url;
    // load the template data from cache (session)  
    $templates = $this->session->get('LswRemoteTemplate.templates');
    // if there are no templates, or not for this url, or the template is too old   
    if (!$templates || !isset($templates[$url]) || time()-$templates[$url]['timestamp']>$this->cacheTimeInSeconds)
    { // make a session cookie
      $cookie = session_name().'='.session_id();
      // close the session
      session_write_close();
      // use the api caller to get the template from the url with the cookie
      $template = $this->apiCaller->call(new HttpGetHtml($url,$cookie));
      // start the session
      session_start();
      // if the templates are not yet initialized as array, initialize
      if (!is_array($templates)) $templates = array();
      // fill the templates data with html and template for the url
      $templates[$url] = array('html'=>gzcompress($template),'timestamp'=>time());
      // store the templates in the session
      $this->session->set('LswRemoteTemplate.templates',$templates);
    }
    else
    { // get the template html from the cache
      $template = gzuncompress($templates[$url]['html']);
    }
    // replace the 'replacement comment' from the config with the template html
    $html = str_replace($this->replacementComment, $html, $template, $replaced);
    // set the html as the content of the reponse 
    $response->setContent($html);
    // throw an Exception when the replacement comment cannot be found
    if (!$replaced) throw new \Exception("RemoteSessionCachedTemplate could not find '$this->replacementComment'");
  }
  
}
