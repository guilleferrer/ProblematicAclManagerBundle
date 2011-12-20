<?php

namespace Problematic\AclManagerBundle\Domain;

use Symfony\Component\Security\Acl\Dbal\MutableAclProvider;
use Symfony\Component\Security\Acl\Domain\Acl;
use Symfony\Component\Security\Acl\Model\SecurityIdentityInterface;
use Symfony\Component\Security\Core\SecurityContext;
use Symfony\Component\Security\Acl\Domain\ObjectIdentity;
use Symfony\Component\Security\Acl\Permission\MaskBuilder;
use Problematic\AclManagerBundle\Model\AclManagerInterface;
use Problematic\AclManagerBundle\Domain\AbstractAclManager;
use Problematic\AclManagerBundle\Model\PermissionContextInterface;

class AclManager extends AbstractAclManager
{

    public function addPermission($domainObject, $securityIdentity, $mask, $type = 'object', $installDefaults = true)
    {
        $context = $this->doCreatePermissionContext($type, $securityIdentity, $mask);
        $oid = new ObjectIdentity(
            $domainObject->getId(),
            ClassIdentity::getClass($domainObject)
        );
        $acl = $this->doLoadAcl($oid);
        $this->doApplyPermission($acl, $context);

        if ($installDefaults) {
            $this->doInstallDefaults($acl);
        }

        $this->getAclProvider()->updateAcl($acl);

        return $this;
    }

    public function revokePermission($domainObject, $securityIdentity, $mask, $type = 'object')
    {
        $context = $this->doCreatePermissionContext($type, $securityIdentity, $mask);
        $oid = new ObjectIdentity(
            $domainObject->getId(),
            ClassIdentity::getClass($domainObject)
        );
        $acl = $this->doLoadAcl($oid);
        $this->doRevokePermission($acl, $context);
        $this->getAclProvider()->updateAcl($acl);

        return $this;
    }

    public function revokeAllPermissions($domainObject, $securityIdentity, $type = 'object')
    {
        $securityIdentity = $this->doCreateSecurityIdentity($securityIdentity);
        $oid = new ObjectIdentity(
            $domainObject->getId(),
            ClassIdentity::getClass($domainObject)
        );
        $acl = $this->doLoadAcl($oid);
        $this->doRevokeAllPermissions($acl, $securityIdentity, $type);
        $this->getAclProvider()->updateAcl($acl);

        return $this;
    }

    public function preloadAcls($objects)
    {
        $oids = array();
        foreach ($objects as $object) {
            $oid = new ObjectIdentity(
                $domainObject->getId(),
                ClassIdentity::getClass($domainObject)
            );
            $oids[] = $oid;
        }

        $acls = $this->getAclProvider()->findAcls($oids); // todo: do we need to do anything with these?

        return $this;
    }

    public function deleteAclFor($domainObject)
    {
        $oid = new ObjectIdentity(
            $domainObject->getId(),
            ClassIdentity::getClass($domainObject)
        );
        $this->getAclProvider()->deleteAcl($oid);

        return $this;
    }

}
