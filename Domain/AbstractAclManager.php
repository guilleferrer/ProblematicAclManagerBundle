<?php

namespace Problematic\AclManagerBundle\Domain;

use Symfony\Component\Security\Acl\Model\MutableAclProviderInterface;
use Symfony\Component\Security\Acl\Model\MutableAclInterface;
use Symfony\Component\Security\Acl\Model\AuditableEntryInterface;
use Symfony\Component\Security\Acl\Model\ObjectIdentityInterface;
use Symfony\Component\Security\Acl\Domain\UserSecurityIdentity;
use Symfony\Component\Security\Acl\Domain\RoleSecurityIdentity;
use Symfony\Component\Security\Acl\Exception\AclAlreadyExistsException;
use Symfony\Component\Security\Acl\Model\SecurityIdentityInterface;
use Symfony\Component\Security\Acl\Permission\MaskBuilder;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Role\RoleInterface;
use Symfony\Component\Security\Core\SecurityContextInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Problematic\AclManagerBundle\Model\PermissionContextInterface;
use Problematic\AclManagerBundle\Model\AclManagerInterface;

/**
 * abstract class containing low-level functionality (plumbing) to be extended by production AclManager (porcelain)
 * note that none of the methods in the abstract class call AclProvider#updatedAcl(); this needs to be taken care
 * of in the concrete implementation
 */
abstract class AbstractAclManager implements AclManagerInterface
{

    private $aclProvider;

    public function __construct(MutableAclProviderInterface $aclProvider)
    {
        $this->aclProvider = $aclProvider;
    }

    /**
     * @return MutableAclProviderInterface
     */
    protected function getAclProvider()
    {
        return $this->aclProvider;
    }

    /**
     * Loads an ACL from the ACL provider, first by attempting to create, then finding if it already exists
     *
     * @param mixed $entity
     * @return MutableAclInterface
     */
    protected function doLoadAcl(ObjectIdentityInterface $objectIdentity)
    {
        $acl = null;
        try {
            $acl = $this->getAclProvider()->createAcl($objectIdentity);
        } catch (AclAlreadyExistsException $ex) {
            $acl = $this->getAclProvider()->findAcl($objectIdentity);
        }

        return $acl;
    }

    protected function doRemoveAcl($token)
    {
        if (!$token instanceof ObjectIdentityInterface) {
            $token = new ObjectIdentity(
                $token->getId(),
                ClassIdentity::getClass($token)
            );
        }

        $this->getAclProvider()->deleteAcl($token);
    }

    /**
     * Returns an instance of PermissionContext. If !$securityIdentity instanceof SecurityIdentityInterface, a new security identity will be created using it
     *
     * @param string $type
     * @param $securityIdentity
     * @param integer $mask
     * @param boolean $granting
     * @return PermissionContext
     */
    protected function doCreatePermissionContext($type, $securityIdentity, $mask, $granting = true)
    {
        if (!$securityIdentity instanceof SecurityIdentityInterface) {
            $securityIdentity = $this->doCreateSecurityIdentity($securityIdentity);
        }

        $permissionContext = new PermissionContext();
        $permissionContext->setPermissionType($type);
        $permissionContext->setSecurityIdentity($securityIdentity);
        $permissionContext->setMask($mask);
        $permissionContext->setGranting($granting);

        return $permissionContext;
    }

    /**
     * Creates a new object instanceof SecurityIdentityInterface from input implementing one of UserInterface, TokenInterface or RoleInterface (or its string representation)
     * @param mixed $identity
     * @throws InvalidIdentityException
     * @return SecurityIdentityInterface
     */
    protected function doCreateSecurityIdentity($identity)
    {

        if (!$identity instanceof UserInterface && !$identity instanceof TokenInterface && !$identity instanceof RoleInterface && !is_string($identity)) {
            throw new \InvalidArgumentException(sprintf('$identity must implement one of: UserInterface, TokenInterface, RoleInterface (%s given)', get_class($identity)));
        }

        $securityIdentity = null;
        if ($identity instanceof UserInterface) {
            $securityIdentity = UserSecurityIdentity::fromAccount($identity);
        } else if ($identity instanceof TokenInterface) {
            $securityIdentity = UserSecurityIdentity::fromToken($identity);
        } else if ($identity instanceof RoleInterface || is_string($identity)) {
            $securityIdentity = new RoleSecurityIdentity($identity);
        }

        if (!$securityIdentity instanceof SecurityIdentityInterface) {
            throw new \InvalidArgumentException('Couldn\'t create a valid SecurityIdentity with the provided identity information');
        }

        return $securityIdentity;
    }

    /**
     * Loads an ACE collection from the ACL and updates the permissions (creating if no appropriate ACE exists)
     *
     * @todo refactor this code to transactionalize ACL updating
     * @param MutableAclInterface $acl
     * @param PermissionContextInterface $context
     * @return void
     */
    protected function doApplyPermission(MutableAclInterface $acl, PermissionContextInterface $context)
    {
        $type = $context->getPermissionType();
        $aceCollection = $this->getAceCollection($acl, $context->getPermissionType());

        $size = count($aceCollection) - 1;
        reset($aceCollection);
        for ($i = $size; $i >= 0; $i--) {
            if ($context->equals($aceCollection[$i])) {
                // an exact match already exists; we don't need to hit the db
                return;
            }
        }

        $acl->{"insert{$type}Ace"}($context->getSecurityIdentity(), $context->getMask(), 0, $context->isGranting());
    }

    protected function doRevokePermission(MutableAclInterface $acl, PermissionContextInterface $context)
    {
        $type = $context->getPermissionType();
        $aceCollection = $this->getAceCollection($acl, $context->getPermissionType());

        $found = false;
        $size = count($aceCollection) - 1;
        reset($aceCollection);
        for ($i = $size; $i >= 0; $i--) {
            if ($context->equals($aceCollection[$i])) {
                $acl->{"delete{$type}Ace"}($i);
                $found = true;
            }
        }

        if (!$found) {
            // create a non-granting ACE for this permission
            $newContext = $this->doCreatePermissionContext($context->getPermissionType(), $context->getSecurityIdentity(), $context->getMask(), false);
            $this->doApplyPermission($acl, $newContext);
        }
    }

    protected function doRevokeAllPermissions(MutableAclInterface $acl, SecurityIdentityInterface $securityIdentity, $type = 'object')
    {
        $aceCollection = $this->getAceCollection($acl, $type);

        $size = count($aceCollection) - 1;
        reset($aceCollection);
        for ($i = $size; $i >= 0; $i--) {
            if ($aceCollection[$i]->getSecurityIdentity() == $securityIdentity) {
                $acl->{"delete{$type}Ace"}($i);
            }
        }
    }

    protected function doInstallDefaults(MutableAclInterface $acl)
    {
        $builder = new MaskBuilder();
        $permissionContexts = array();

        $permissionContexts[] = $this->doCreatePermissionContext('class', 'ROLE_SUPER_ADMIN', MaskBuilder::MASK_IDDQD);
        $permissionContexts[] = $this->doCreatePermissionContext('class', 'ROLE_ADMIN', MaskBuilder::MASK_MASTER);
        $permissionContexts[] = $this->doCreatePermissionContext('class', 'IS_AUTHENTICATED_ANONYMOUSLY', MaskBuilder::MASK_VIEW);

        $builder->add('VIEW');
        $builder->add('CREATE');
        $permissionContexts[] = $this->doCreatePermissionContext('class', 'ROLE_USER', $builder->get());

        reset($permissionContexts);
        foreach ($permissionContexts as $context) {
            $this->doApplyPermission($acl, $context);
        }
    }

    private function getAceCollection(MutableAclInterface $acl, $type = 'object')
    {
        $aceCollection = $acl->{"get{$type}Aces"}();

        return $aceCollection;
    }

}
