<?php

namespace Lsw\RemoteTemplateBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Lsw\RemoteTemplateBundle\DependencyInjection\LswRemoteTemplateExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class LswRemoteTemplateBundle extends Bundle
{
  public function build(ContainerBuilder $container)
  {
    parent::build($container);
    $container->registerExtension(new LswRemoteTemplateExtension());
  }
}
