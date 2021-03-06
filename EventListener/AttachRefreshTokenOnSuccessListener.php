<?php

/*
 * This file is part of the GesdinetJWTRefreshTokenBundle package.
 *
 * (c) Gesdinet <http://www.gesdinet.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Gesdinet\JWTRefreshTokenBundle\EventListener;

use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Request\RequestRefreshToken;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PropertyAccess\PropertyAccessor;

class AttachRefreshTokenOnSuccessListener
{
    /**
     * @var RefreshTokenManagerInterface
     */
    protected $refreshTokenManager;

    /**
     * @var int
     */
    protected $ttl;

    /**
     * @var ValidatorInterface
     */
    protected $validator;

    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var string
     */
    protected $userIdentityField;

    /**
     * @var string
     */
    protected $tokenParameterName;

    /**
     * @var bool
     */
    protected $singleUse;

    /**
     * @var string
     */
    protected $fingerprintKey;

    /**
     * AttachRefreshTokenOnSuccessListener constructor.
     *
     * @param RefreshTokenManagerInterface $refreshTokenManager
     * @param int                          $ttl
     * @param ValidatorInterface           $validator
     * @param RequestStack                 $requestStack
     * @param string                       $userIdentityField
     * @param string                       $tokenParameterName
     * @param bool                         $singleUse
     * @param string                       $fingerprintKey
     */
    public function __construct(
        RefreshTokenManagerInterface $refreshTokenManager,
        $ttl,
        ValidatorInterface $validator,
        RequestStack $requestStack,
        $userIdentityField,
        $tokenParameterName,
        $singleUse,
        $fingerprintKey
    ) {
        $this->refreshTokenManager = $refreshTokenManager;
        $this->ttl = $ttl;
        $this->validator = $validator;
        $this->requestStack = $requestStack;
        $this->userIdentityField = $userIdentityField;
        $this->tokenParameterName = $tokenParameterName;
        $this->singleUse = $singleUse;
        $this->fingerprintKey = $fingerprintKey;
    }

    public function attachRefreshToken(AuthenticationSuccessEvent $event)
    {
        $data = $event->getData();
        $user = $event->getUser();
        $request = $this->requestStack->getCurrentRequest();
        $fingerprint = $request->get($this->fingerprintKey, false);

        if (!$user instanceof UserInterface) {
            return;
        }

        if (!$fingerprint) {
            throw new BadRequestHttpException(sprintf('Field %s is required!', $this->fingerprintKey));
        }

        $refreshTokenString = RequestRefreshToken::getRefreshToken($request, $this->tokenParameterName);

        if ($refreshTokenString && true === $this->singleUse) {
            $refreshToken = $this->refreshTokenManager->get($refreshTokenString, $fingerprint);
            $refreshTokenString = null;

            if ($refreshToken instanceof RefreshTokenInterface) {
                $this->refreshTokenManager->delete($refreshToken);
            }
        } else if (!$refreshTokenString) {
            $refreshToken = $this->refreshTokenManager->getByUsernameAndFingerprint($user->getUsername(), $fingerprint);

            if ($refreshToken) {
                $refreshTokenString = $refreshToken->getRefreshToken();
            }
        }

        if ($refreshTokenString) {
            $data[$this->tokenParameterName] = $refreshTokenString;
        } else {
            $datetime = new \DateTime();
            $datetime->modify('+'.$this->ttl.' seconds');

            $refreshToken = $this->refreshTokenManager->create();

            $accessor = new PropertyAccessor();
            $userIdentityFieldValue = $accessor->getValue($user, $this->userIdentityField);

            $refreshToken->setUsername($userIdentityFieldValue);
            $refreshToken->setFingerprint($fingerprint);
            $refreshToken->setRefreshToken();
            $refreshToken->setValid($datetime);

            $valid = false;
            while (false === $valid) {
                $valid = true;
                $errors = $this->validator->validate($refreshToken);
                if ($errors->count() > 0) {
                    foreach ($errors as $error) {
                        if ('refreshToken' === $error->getPropertyPath()) {
                            $valid = false;
                            $refreshToken->setRefreshToken();
                        }
                    }
                }
            }

            $this->refreshTokenManager->save($refreshToken);
            $data[$this->tokenParameterName] = $refreshToken->getRefreshToken();
        }

        $event->setData($data);
    }
}
