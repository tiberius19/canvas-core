<?php

declare(strict_types=1);

namespace Canvas\Api\Controllers;

use Baka\Auth\UserProvider;
use Baka\Http\Exception\InternalServerErrorException;
use Baka\Http\Exception\NotFoundException;
use Baka\Validation as CanvasValidation;
use Baka\Validations\PasswordValidation;
use Canvas\Auth\Auth;
use Canvas\Auth\Factory;
use Canvas\Contracts\AuthTrait;
use Canvas\Contracts\Jwt\TokenTrait;
use Canvas\Contracts\SocialLoginTrait;
use Canvas\Models\RegisterRoles;
use Canvas\Models\Sessions;
use Canvas\Models\Sources;
use Canvas\Models\UserLinkedSources;
use Canvas\Models\Users;
use Canvas\Notifications\PasswordUpdate;
use Canvas\Notifications\ResetPassword;
use Canvas\Notifications\Signup;
use Canvas\Notifications\UpdateEmail;
use Exception;
use Phalcon\Http\Response;
use Phalcon\Validation\Validator\Confirmation;
use Phalcon\Validation\Validator\Email as EmailValidator;
use Phalcon\Validation\Validator\PresenceOf;
use Phalcon\Validation\Validator\StringLength;

class AuthController extends BaseController
{
    /**
     * Auth Trait.
     */
    use AuthTrait;
    use TokenTrait;
    use SocialLoginTrait;

    /**
     * Setup for this controller.
     *
     * @return void
     */
    public function onConstruct()
    {
        $this->userLinkedSourcesModel = new UserLinkedSources();
        $this->userModel = new Users();

        if (!isset($this->config->jwt)) {
            throw new InternalServerErrorException('You need to configure your app JWT');
        }
    }

    /**
     * User Login.
     *
     * @method POST
     * @url /v1/auth
     *
     * @return Response
     */
    public function login() : Response
    {
        $request = $this->request->getPostData();

        $userIp = !defined('API_TESTS') ? $this->request->getClientAddress(true) : '127.0.0.1'; //help getting the client ip on scrutinizer :(
        $admin = 0;
        $remember = 1;

        //Ok let validate user password
        $validation = new CanvasValidation();
        $validation->add('email', new EmailValidator(['message' => _('The email is not valid')]));
        $validation->add('password', new PresenceOf(['message' => _('The password is required.')]));

        $validation->setFilters('name', 'trim');
        $validation->setFilters('password', 'trim');

        //validate this form for password
        $validation->validate($request);

        $email = $validation->getValue('email');
        $password = $validation->getValue('password');

        /**
         * Login the user via ecosystem or app.
         */
        $auth = Factory::create($this->app->ecosystemAuth());
        $userData = $auth::login($email, $password, $remember, $admin, $userIp);
        $token = $userData->getToken();

        //start session
        $session = new Sessions();
        $session->start($userData, $token['sessionId'], $token['token'], $userIp, 1);

        return $this->response([
            'token' => $token['token'],
            'refresh_token' => $token['refresh_token'],
            'time' => date('Y-m-d H:i:s'),
            'expires' => $token['token_expiration'],
            'refresh_token_expires' => $token['refresh_token_expiration'],
            'id' => $userData->getId()
        ]);
    }

    /**
     * User Signup.
     *
     * @method POST
     * @url /v1/users
     *
     * @return Response
     */
    public function signup() : Response
    {
        $user = UserProvider::get();

        $request = $this->request->getPostData();

        //Ok let validate user password
        $validation = new CanvasValidation();
        $validation->add('password', new PresenceOf(['message' => _('The password is required.')]));
        $validation->add('email', new EmailValidator(['message' => _('The email is not valid.')]));

        $validation->add(
            'password',
            new StringLength([
                'min' => 8,
                'messageMinimum' => _('Password is too short. Minimum 8 characters.'),
            ])
        );

        $validation->add('password', new Confirmation([
            'message' => _('Password and confirmation do not match.'),
            'with' => 'verify_password',
        ]));

        $validation->setFilters('password', 'trim');
        $validation->setFilters('firstname', 'trim');
        $validation->setFilters('lastname', 'trim');
        $validation->setFilters('displayname', 'trim');
        $validation->setFilters('default_company', 'trim');

        //validate this form for password
        $validation->validate($request);

        $user->email = $validation->getValue('email');
        $user->firstname = $validation->getValue('firstname');
        $user->lastname = $validation->getValue('lastname');
        $user->password = $validation->getValue('password');
        $user->displayname = !empty($validation->getValue('displayname')) ? $validation->getValue('displayname') : $user->generateDefaultDisplayname();
        $userIp = !defined('API_TESTS') ? $this->request->getClientAddress() : '127.0.0.1'; //help getting the client ip on scrutinizer :(
        $user->defaultCompanyName = $validation->getValue('default_company');

        //user registration
        try {
            $this->db->begin();

            $user = Auth::signUp($user);

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();

            throw new Exception($e->getMessage());
        }

        $token = $user->getToken();

        //start session
        $session = new Sessions();
        $session->start($user, $token['sessionId'], $token['token'], $userIp, 1);

        $authSession = [
            'token' => $token['token'],
            'time' => date('Y-m-d H:i:s'),
            'expires' => $token['token_expiration'],
            'id' => $user->getId(),
        ];

        $user->password = '';
        $user->notify(new Signup($user));

        return $this->response([
            'user' => $user,
            'session' => $authSession
        ]);
    }

    /**
     * User Signup.
     *
     * @method POST
     * @url /v1/users
     *
     * @return Response
     */
    public function signupByRegisterRole() : Response
    {
        $user = $this->userModel;

        $request = $this->request->getPostData();

        //Ok let validate user password
        $validation = new CanvasValidation();
        $validation->add('password', new PresenceOf(['message' => _('The password is required.')]));
        $validation->add('roles_uuid', new PresenceOf(['message' => _('roles_uuid is required.')]));
        $validation->add('email', new EmailValidator(['message' => _('The email is not valid.')]));

        $validation->add(
            'password',
            new StringLength([
                'min' => 8,
                'messageMinimum' => _('Password is too short. Minimum 8 characters.'),
            ])
        );

        $validation->add('password', new Confirmation([
            'message' => _('Password and confirmation do not match.'),
            'with' => 'verify_password',
        ]));

        $validation->setFilters('password', 'trim');
        $validation->setFilters('firstname', 'trim');
        $validation->setFilters('lastname', 'trim');
        $validation->setFilters('displayname', 'trim');
        $validation->setFilters('default_company', 'trim');

        //validate this form for password
        $validation->validate($request);

        $registerRole = RegisterRoles::getByUuid($request['roles_uuid']);

        $user->email = $validation->getValue('email');
        $user->firstname = $validation->getValue('firstname');
        $user->lastname = $validation->getValue('lastname');
        $user->password = $validation->getValue('password');
        $user->displayname = !empty($validation->getValue('displayname')) ? $validation->getValue('displayname') : $user->generateDefaultDisplayname();
        $userIp = !defined('API_TESTS') ? $this->request->getClientAddress() : '127.0.0.1'; //help getting the client ip on scrutinizer :(
        $user->defaultCompanyName = $validation->getValue('default_company');
        $user->roles_id = $registerRole->roles_id;

        //user registration
        try {
            $this->db->begin();

            $user = Auth::signUp($user->toArray());

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();

            throw new Exception($e->getMessage());
        }

        $token = $user->getToken();

        //start session
        $session = new Sessions();
        $session->start($user, $token['sessionId'], $token['token'], $userIp, 1);

        $authSession = [
            'token' => $token['token'],
            'time' => date('Y-m-d H:i:s'),
            'expires' => $token['token_expiration'],
            'id' => $user->getId(),
        ];

        $user->password = '';
        $user->notify(new Signup($user));

        return $this->response([
            'user' => $user,
            'session' => $authSession
        ]);
    }

    /**
     * Refresh user auth.
     *
     * @return Response
     *
     * @todo Validate access_token and refresh token, session's user email and re-login
     */
    public function refresh() : Response
    {
        $request = $this->request->getPostData();
        $accessToken = $this->getToken($request['access_token']);
        $refreshToken = $this->getToken($request['refresh_token']);
        $user = null;

        if (!$accessToken->isExpired()) {
            throw new InternalServerErrorException('Issued Access Token has not expired');
        }
        if ($refreshToken->isExpired()) {
            throw new InternalServerErrorException('Refresh Token has expired');
        }

        //Check if both tokens relate to the same user's email
        if ($accessToken->claims()->get('sessionId') === $refreshToken->claims()->get('sessionId') && !is_null($accessToken->claims()->get('email'))) {
            /**
             * @todo confirm the refresh token exist and is valid from the DB
             */
            $user = Users::getByEmail($accessToken->claims()->get('email'));
        }

        if (!$user) {
            throw new NotFoundException(_('User not found'));
        }

        $token = Sessions::restart(
            $user,
            $refreshToken->claims()->get('sessionId'),
            (string)$this->request->getClientAddress()
        );

        return $this->response([
            'token' => $token['token'],
            'refresh_token' => $token['refresh_token'],
            'time' => date('Y-m-d H:i:s'),
            'expires' => $token['token_expiration'],
            'refresh_token_expires' => $token['refresh_token_expiration'],
            'id' => $user->getId()
        ]);
    }

    /**
     * Send email to change current email for user.
     *
     * @param int $id
     *
     * @return Response
     */
    public function sendEmailChange(int $id) : Response
    {
        //Search for user
        $user = Users::getById($id);

        $user->notify(new UpdateEmail($user));

        return $this->response($user);
    }

    /**
     * Change user's email.
     *
     * @param string $hash
     *
     * @return Response
     */
    public function changeUserEmail(string $hash) : Response
    {
        $request = $this->request->getPostData();

        //Ok let validate user password
        $validation = new CanvasValidation();
        $validation->add('password', new PresenceOf(['message' => _('The password is required.')]));
        $validation->add('new_email', new EmailValidator(['message' => _('The email is not valid.')]));

        $validation->add(
            'password',
            new StringLength([
                'min' => 8,
                'messageMinimum' => _('Password is too short. Minimum 8 characters.'),
            ])
        );

        //validate this form for password
        $validation->setFilters('password', 'trim');
        $validation->setFilters('default_company', 'trim');
        $validation->validate($request);

        $newEmail = $validation->getValue('new_email');
        $password = $validation->getValue('password');

        //Search user by key
        $user = Users::getByUserActivationEmail($hash);

        if (!is_object($user)) {
            throw new NotFoundException(_('User not found'));
        }

        $this->db->begin();

        $user->email = $newEmail;
        $user->updateOrFail();

        if (!$userData = $this->loginUsers($user->email, $password)) {
            $this->db->rollback();
        }

        $this->db->commit();

        return $this->response($userData);
    }

    /**
     * Login user using Access Token.
     *
     * @return Response
     */
    public function loginBySocial() : Response
    {
        $request = $this->request->getPostData();

        $source = Sources::findFirstOrFail([
            'title = ?0 and is_deleted = 0',
            'bind' => [$request['provider']]
        ]);

        if ($source->isApple()) {
            $appleUserInfo = $source->validateAppleUser($request['social_id']);
            $request['social_id'] = $appleUserInfo->sub;
            $request['email'] = $appleUserInfo->email;
        }

        return $this->response(
            $this->providerLogin($source, $request['social_id'], $request)
        );
    }

    /**
     * Reset the user password.
     *
     * @method PUT
     * @url /v1/reset
     *
     * @return Response
     */
    public function reset(string $key) : Response
    {
        //is the key empty or does it exist?
        if (empty($key) || !$userData = Users::findFirst(['user_activation_forgot = :key:', 'bind' => ['key' => $key]])) {
            throw new Exception(_('This Key to reset password doesn\'t exist'));
        }

        $request = $this->request->getPostData();

        // Get the new password and the verify
        $newPassword = trim($request['new_password']);
        $verifyPassword = trim($request['verify_password']);

        //Ok let validate user password
        PasswordValidation::validate($newPassword, $verifyPassword);

        // Has the password and set it
        $userData->resetPassword($newPassword);
        $userData->user_activation_forgot = '';
        $userData->updateOrFail();

        //log the user out of the site from all devices
        $session = new Sessions();
        $session->end($userData);

        $userData->notify(new PasswordUpdate($userData));

        return $this->response(_('Password Updated'));
    }

    /**
     * Send the user how filled out the form to the specify email
     * a link to reset his password.
     *
     * @return Response
     */
    public function recover() : Response
    {
        $request = $this->request->getPostData();

        $validation = new CanvasValidation();
        $validation->add('email', new EmailValidator(['message' => _('The email is not valid.')]));
        $validation->validate($request);

        $email = $validation->getValue('email');

        $recoverUser = Users::getByEmail($email);
        $recoverUser->generateForgotHash();

        $recoverUser->notify(new ResetPassword($recoverUser));

        return $this->response(_('Check your email to recover your password'));
    }
}
