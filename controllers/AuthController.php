<?php

namespace webvimark\modules\UserManagement\controllers;

use webvimark\components\BaseController;
use webvimark\modules\UserManagement\components\UserAuthEvent;
use webvimark\modules\UserManagement\models\forms\ChangeOwnPasswordForm;
use webvimark\modules\UserManagement\models\forms\ConfirmEmailForm;
use webvimark\modules\UserManagement\models\forms\LoginForm;
use webvimark\modules\UserManagement\models\forms\PasswordRecoveryForm;
use webvimark\modules\UserManagement\models\User;
use webvimark\modules\UserManagement\UserManagementModule;
use Yii;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\widgets\ActiveForm;

class AuthController extends BaseController
{
	/**
	 * @var array
	 */
	public $freeAccessActions = ['login', 'logout', 'confirm-registration-email', 'password-recovery-receive'];

	/**
	 * @return array
	 */
	public function actions()
	{
		return [
			'captcha' => $this->module->captchaOptions,
		];
	}

	/**
	 * Login form
	 *
	 * @return string
	 */
	public function actionLogin()
	{
		if ( !Yii::$app->user->isGuest )
		{
			return $this->goHome();
		}

		$model = new LoginForm();
		$data = [];

		if(Yii::$app->request->post()){
			$data = Yii::$app->request->post();
		}

		if ( Yii::$app->request->isAjax AND $model->load($data) )
		{
			Yii::$app->response->format = Response::FORMAT_JSON;
			return ActiveForm::validate($model);
		}

		if($model->load($data) AND $model->verifyFirstAccess()){
			
			if(isset($data['new_password']) && !empty($data['new_password'])){

				$model->password = $data['new_password'];
				$model->User->password_hash = Yii::$app->getSecurity()->generatePasswordHash($data['new_password']);
				$model->User->primeiro_acesso = 1;
				
				if($model->User->save() AND $model->login()){
					return $this->goBack();
				}else{
					Yii::$app->session->setFlash('error', Yii::t('app','Ops! Tivemos um problema, tente novamente mais tarde ou contate o administrador do sistema.'));
					return $this->goHome();
				}

			}else{
				Yii::$app->session->set('first_access', true);
				Yii::$app->session->set('username', $model->username);
				Yii::$app->session->set('password', $model->password);
				return $this->goHome();
			}
		}

		if ( $model->load($data) AND $model->login() )
		{
			return $this->goBack();
		}

		return $this->renderIsAjax('login', compact('model'));
	}

	/**
	 * Logout and redirect to home page
	 */
	public function actionLogout()
	{
		Yii::$app->user->logout();

		return $this->redirect(Yii::$app->homeUrl);
	}

	/**
	 * Change your own password
	 *
	 * @throws \yii\web\ForbiddenHttpException
	 * @return string|\yii\web\Response
	 */
	public function actionChangeOwnPassword()
	{
		if ( Yii::$app->user->isGuest )
		{
			return $this->goHome();
		}

		$user = User::getCurrentUser();

		if ( $user->status != User::STATUS_ACTIVE )
		{
			throw new ForbiddenHttpException();
		}

		$model = new ChangeOwnPasswordForm(['user'=>$user]);


		if ( Yii::$app->request->isAjax AND $model->load(Yii::$app->request->post()) )
		{
			Yii::$app->response->format = Response::FORMAT_JSON;
			return ActiveForm::validate($model);
		}

		if ( $model->load(Yii::$app->request->post()) AND $model->changePassword() )
		{
			return $this->renderIsAjax('changeOwnPasswordSuccess');
		}

		return $this->renderIsAjax('changeOwnPassword', compact('model'));
	}

	/**
	 * Registration logic
	 *
	 * @return string
	 */
	public function actionRegistration()
	{
		if ( !Yii::$app->user->isGuest )
		{
			return $this->goHome();
		}

		$model = new $this->module->registrationFormClass;


		if ( Yii::$app->request->isAjax AND $model->load(Yii::$app->request->post()) )
		{

			Yii::$app->response->format = Response::FORMAT_JSON;

			// Ajax validation breaks captcha. See https://github.com/yiisoft/yii2/issues/6115
			// Thanks to TomskDiver
			$validateAttributes = $model->attributes;
			unset($validateAttributes['captcha']);

			return ActiveForm::validate($model, $validateAttributes);
		}

		if ( $model->load(Yii::$app->request->post()) AND $model->validate() )
		{
			// Trigger event "before registration" and checks if it's valid
			if ( $this->triggerModuleEvent(UserAuthEvent::BEFORE_REGISTRATION, ['model'=>$model]) )
			{
				$user = $model->registerUser(false);

				// Trigger event "after registration" and checks if it's valid
				if ( $this->triggerModuleEvent(UserAuthEvent::AFTER_REGISTRATION, ['model'=>$model, 'user'=>$user]) )
				{
					if ( $user )
					{
						if ( Yii::$app->getModule('user-management')->useEmailAsLogin AND Yii::$app->getModule('user-management')->emailConfirmationRequired )
						{
							return $this->renderIsAjax('registrationWaitForEmailConfirmation', compact('user'));
						}
						else
						{
							$roles = (array)$this->module->rolesAfterRegistration;

							foreach ($roles as $role)
							{
								User::assignRole($user->id, $role);
							}

							Yii::$app->user->login($user);

							return $this->redirect(Yii::$app->user->returnUrl);
						}

					}
				}
			}

		}

		return $this->renderIsAjax('registration', compact('model'));
	}


	/**
	 * Receive token after registration, find user by it and confirm email
	 *
	 * @param string $token
	 *
	 * @throws \yii\web\NotFoundHttpException
	 * @return string|\yii\web\Response
	 */
	public function actionConfirmRegistrationEmail($token)
	{
		if ( Yii::$app->getModule('user-management')->useEmailAsLogin AND Yii::$app->getModule('user-management')->emailConfirmationRequired )
		{
			$model = new $this->module->registrationFormClass;

			$user = $model->checkConfirmationToken($token);

			if ( $user )
			{
				return $this->renderIsAjax('confirmEmailSuccess', compact('user'));
			}

			throw new NotFoundHttpException(UserManagementModule::t('front', 'Token not found. It may be expired'));
		}
	}


	/**
	 * Form to recover password
	 *
	 * @return string|\yii\web\Response
	 */
	public function actionPasswordRecovery()
	{
		$result = [
			'check' => true,
			'message' => 'Solicitação enviada! Verifique o E-mail vinculado a esse usuário para mais informações.'
		];

		$data = Yii::$app->request->post();

		if(!isset($data['username']) || empty($data['username']))
		{
			$result['check'] = false;
			$result['message'] = 'Ops! Houve um problema na requisição, tente novamente mais tarde ou contate o(a) administrador(a) do sistema.';
			echo json_encode($result);
			exit;
		}

		$user = User::findOne(['username' => $data['username']]);

		if(is_null($user))
		{
			$result['check'] = false;
			$result['message'] = 'Usuário não encontrado! Informe um usuário válido ou contate o(a) administrador(a) do sistema.';
			echo json_encode($result);
			exit;
		}

		if(is_null($user->email) || $user->email == '')
		{
			$result['check'] = false;
			$result['message'] = 'O usuário informado não possui um E-mail cadastrado! Contate o(a) administrador(a) do sistema.';
			echo json_encode($result);
			exit;
		}

		$model = new PasswordRecoveryForm();

		if ( !$model->sendEmail($user) )
		{
			$result['check'] = false;
			$result['message'] = 'Ops! Houve um problema com o envio do email, tente novamente mais tarde ou contate o(a) administrador(a) do sistema.';
			echo json_encode($result);
			exit;
		}

		echo json_encode($result);
		exit;
	}

	/**
	 * Receive token, find user by it and show form to change password
	 *
	 * @param string $token
	 *
	 * @throws \yii\web\NotFoundHttpException
	 * @return string|\yii\web\Response
	 */
	public function actionPasswordRecoveryReceive($token)
	{
		if ( !Yii::$app->user->isGuest )
		{
			return $this->goHome();
		}

		$this->layout = 'loginLayout';

		$user = User::findByConfirmationToken($token);

		if ( !$user )
		{
			Yii::$app->session->setFlash('error', "Token não encontrado. Pode estar expirado. Tente redefinir a senha mais uma vez.");

			return $this->redirect(['/user-management/auth/login']);
		}

		$model = new ChangeOwnPasswordForm([
			'scenario'=>'restoreViaEmail',
			'user'=>$user,
		]);

		if ( $model->load(Yii::$app->request->post()) AND $model->validate() )
		{
			if ( $this->triggerModuleEvent(UserAuthEvent::BEFORE_PASSWORD_RECOVERY_COMPLETE, ['model'=>$model]) )
			{
				$model->changePassword(false);

				if ( $this->triggerModuleEvent(UserAuthEvent::AFTER_PASSWORD_RECOVERY_COMPLETE, ['model'=>$model]) )
				{
					Yii::$app->session->setFlash('success', "Senha atualizada com sucesso!");

					return $this->redirect(['/user-management/auth/login']);
				}
			}
			
		}

		return $this->renderIsAjax('changeOwnPassword', compact('model'));
	}

	/**
	 * @return string|\yii\web\Response
	 */
	public function actionConfirmEmail()
	{
		if ( Yii::$app->user->isGuest )
		{
			return $this->goHome();
		}

		$user = User::getCurrentUser();

		if ( $user->email_confirmed == 1 )
		{
			return $this->renderIsAjax('confirmEmailSuccess', compact('user'));
		}

		$model = new ConfirmEmailForm([
			'email'=>$user->email,
			'user'=>$user,
		]);

		if ( Yii::$app->request->isAjax AND $model->load(Yii::$app->request->post()) )
		{
			Yii::$app->response->format = Response::FORMAT_JSON;
			return ActiveForm::validate($model);
		}

		if ( $model->load(Yii::$app->request->post()) AND $model->validate() )
		{
			if ( $this->triggerModuleEvent(UserAuthEvent::BEFORE_EMAIL_CONFIRMATION_REQUEST, ['model'=>$model]) )
			{
				if ( $model->sendEmail(false) )
				{
					if ( $this->triggerModuleEvent(UserAuthEvent::AFTER_EMAIL_CONFIRMATION_REQUEST, ['model'=>$model]) )
					{
						return $this->refresh();
					}
				}
				else
				{
					Yii::$app->session->setFlash('error', UserManagementModule::t('front', "Unable to send message for email provided"));
				}
			}
		}

		return $this->renderIsAjax('confirmEmail', compact('model'));
	}

	/**
	 * Receive token, find user by it and confirm email
	 *
	 * @param string $token
	 *
	 * @throws \yii\web\NotFoundHttpException
	 * @return string|\yii\web\Response
	 */
	public function actionConfirmEmailReceive($token)
	{
		$user = User::findByConfirmationToken($token);

		if ( !$user )
		{
			throw new NotFoundHttpException(UserManagementModule::t('front', 'Token not found. It may be expired'));
		}
		
		$user->email_confirmed = 1;
		$user->removeConfirmationToken();
		$user->save(false);

		return $this->renderIsAjax('confirmEmailSuccess', compact('user'));
	}

	/**
	 * Universal method for triggering events like "before registration", "after registration" and so on
	 *
	 * @param string $eventName
	 * @param array  $data
	 *
	 * @return bool
	 */
	protected function triggerModuleEvent($eventName, $data = [])
	{
		$event = new UserAuthEvent($data);

		$this->module->trigger($eventName, $event);

		return $event->isValid;
	}
}
