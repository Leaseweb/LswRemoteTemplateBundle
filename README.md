LswRemoteTemplateBundle
=======================

On December 21, 2011 Stefan Koopmanschap wrote an excellent article on the LeaseWeb Labs blog
titled “Painless (well, less painful) migration to Symfony2.” In his article he explained the
advantages of doing a gradual migration. 

see: http://www.leaseweblabs.com/2011/12/painless-well-less-painful-migration-to-symfony2/

The technical solution that he proposed to make this
possible was to “...wrap your old application into your Symfony2 application.” He even provided
us the tool (The IngewikkeldWrapperBundle code) to do so.

see: https://github.com/Ingewikkeld/IngewikkeldWrapperBundle

We were very much inspired by his passionate elucidation and we were fully convinced of the
urge to start migrating to Symfony2 as soon as possible. However, he also provided us with a
“A word of caution” about 2 things: performance and authentication/authorization. This might
get some people worried, but not us: it challenged us to find a solution for those two open issues.

1. Performance
----

As Stefan Koopmanschap explains, in his solution you “...use two frameworks for all your legacy
pages” and “...two frameworks add more overhead than one.” This got us a little worried.
Losing performance was not really an option, so we had to find a solution.

Symfony 1 & 2 both use a Front Controller architecture (one file handling all requests) we were
just looking for seperating traffic between the two front controllers. Stefan proposed to do so
using Symfony 2 routing and make it use a fall-back route to handle your legacy URLs. We hereby
propose to do it using a .htaccess rewrite. This has virtually no overhead, because every Symfony
request gets rewritten by mod_rewrite anyway.

2. Authentication/authorization
----

He also wrote: “Another issue is sessions.” Further clarifying the problem by stating: “If your
application works with authentication and authorization, you’ll now have to work with two different
systems that have a different approach to authentication and authorization”. Since our application
requires both authentication and authorization we had to come up with a solution here. We decided
to move the authentication (login page) to Symfony2 and make Symfony1 “trust” this authentication
done by “Symfony2”.

To realize this solution we had to enable Symfony1 to “see” and “understand” the Symfony2 session.
First we made sure that both applications use the same name by setting the Symfony2 “framework_session_name”
setting in “app/config/config.yml” to “symfony”. Then we reverse engineered the Symfony2 session
storage and found that it serializes some PHP object into it. To be able to unserialize those objects
we had to register an autoload function in Symfony1 using “spl_autoload_register”

Finally, instructions

To solve the performance problem we installed Symfony2 in the “sf2” directory inside the Symfony1
project (next to “apps”) and we started by changing the lines in our “web/.htaccess” file from:

```
# redirect to front controller
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php [QSA,L]
```

And added these lines above it:

``` bash
# redirect to new symfony2 front controller
RewriteCond %{REQUEST_FILENAME} !-f
# but only if URL matches one from this list:
RewriteCond %{REQUEST_URI} ^/users/login
# end of list
RewriteRule ^(.*)$ sf2/web/$1 [QSA,L]
```

To support the Symfony2 authentication and authorization in Symfony1 we created a
“Symfony2AuthenticationFilter” class. This filter can be loaded by putting it under “lib/filter”
folder in your Symfony1 project and add the following lines in “apps/ssc/config/filters.yml”:

```
symfony2AuthenticationFilter:
    class: Symfony2AuthenticationFilter
```

For configuration of the filter we added a few new application settings to “/apps/ssc/config/app.yml”:

```
all:
    symfony2:
        paths: ['sf2/vendor/symfony/src', 'sf2/src', 'sf2/vendor/bundles', 'sf2/vendor/doctrine-common/lib']
        attribute: '_security_main'
```

This path setting shows that Symfony2 is located in the “sf2” sub-directory of the Symfony1 project.
The attribute reflects the name of the Symfony2 firewall. The code of the Symfony2AuthenticationFilter
is this:

``` php
function symfony2_autoload ($pClassName)
{
  $sf2Paths = sfConfig::get('app_symfony2_paths');

  foreach ($sf2Paths as $path)
  {
    $path = sfConfig::get('sf_root_dir') . DIRECTORY_SEPARATOR . $path;
    $file = $path . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR ,$pClassName ) . ".php";

    if (file_exists($file))
    {
      include($file);
      break;
    }
  }
}

spl_autoload_register("symfony2_autoload");

use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\User;
use Symfony\Component\Security\Core\Role\Role;

class Symfony2AuthenticationFilter extends sfFilter
{
  public function execute($filterChain)
  { // get session data
    $sessionData = null;
    $symfony2Attribute = sfConfig::get('app_symfony2_attribute');
    if (isset($_SESSION['_symfony2']['attributes'][$symfony2Attribute]))
    { $sessionData = unserialize($_SESSION['_symfony2']['attributes'][$symfony2Attribute]);
    }
    // get sf1 username
    if (!$this->getContext()->getUser()->isAuthenticated()) $sf1UserName = false;
    else $sf1UserName = $this->getContext()->getUser()->getUserName();
    // get sf2 username
    if (!$sessionData) $sf2UserName = false;
    else $sf2UserName = $sessionData->getUser()->getUserName();
    // if usernames do not match
    if ($sf1UserName!=$sf2UserName)
    { if ($sf2UserName) // if symfony2 is signed in
      { // signin to symfony1
        $this->getContext()->getUser()->setUserName($sf2UserName);
        $this->getContext()->getUser()->setAuthenticated(true);
        $this->getContext()->getUser()->clearCredentials();
      }
      else // if symfony2 is not signed in
      { // signout from symfony1
        $this->getContext()->getUser()->setUserName(false);
        $this->getContext()->getUser()->setAuthenticated(false);
        $this->getContext()->getUser()->clearCredentials();
        // redirect to current page
        $path = $this->getContext()->getRequest()->getPathInfo();
        $this->getContext()->getController()->redirect($path);
      }
    }
    // and execute next filter
    $filterChain->execute();
  }
}
```
