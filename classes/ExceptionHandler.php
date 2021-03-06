<?php

namespace OFFLINE\Sentry\Classes;

use Backend\Facades\BackendAuth;
use RainLab\User\Facades\Auth;
use System\Classes\PluginManager;
use System\Models\Parameter;

class ExceptionHandler extends \October\Rain\Foundation\Exception\Handler
{
    public function report(\Exception $exception)
    {
        if ($this->reportToSentry($exception)) {
            /** @var \Illuminate\Foundation\Application $app */
            $app = app();
            /** @var \Raven_Client $sentry */
            $sentry = $app['sentry'];

            list($userContext, $extraUserContext) = $this->getUserContext();

            $extraContext = $this->getExtraContext($extraUserContext);

            $sentry->user_context($userContext);
            $sentry->setEnvironment($app->environment());
            $sentry->extra_context($extraContext);

            $sentry->captureException($exception);
        }

        parent::report($exception);
    }

    /**
     * Check if this exception should be reported to Sentry.
     *
     * @param \Exception $exception
     *
     * @return bool
     */
    protected function reportToSentry(\Exception $exception)
    {
        return app()->bound('sentry') && $this->shouldReport($exception);
    }

    /**
     * Returns the logged in backend user.
     *
     * @return array
     */
    protected function backendUser(): array
    {
        if (BackendAuth::check()) {
            return BackendAuth::getUser()->toArray() + ['user_type' => 'backend'];
        }

        return ['id' => null];
    }

    /**
     * Returns the logged in rainlab.user.
     *
     * @return array
     */
    protected function frontendUser(): array
    {
        if ($this->rainlabUserInstalled()) {
            $user = Auth::getUser();

            return $user ? $user->toArray() + ['user_type' => 'rainlab.user'] : [];
        }

        return ['id' => null];
    }

    /**
     * Checks if the RainLab.User plugin is installed and enabled.
     *
     * @return bool
     */
    protected function rainlabUserInstalled()
    {
        return PluginManager::instance()->exists('RainLab.User')
            && ! PluginManager::instance()->isDisabled('RainLab.User');
    }

    /**
     * Builds the extra context array.
     *
     * @param $extraUserContext
     *
     * @return array
     */
    protected function getExtraContext($extraUserContext): array
    {
        $extra                  = [];
        $extra['october_build'] = Parameter::get('system::core.build');
        $extra['other_user']    = $extraUserContext;

        return $extra;
    }

    /**
     * If the app is running in the backend the primary user is the
     * backend user. Use the RainLab.User session if the error happend
     * in the frontend.
     *
     * @return array
     */
    protected function getUserContext(): array
    {
        if (app()->runningInBackend()) {
            $userContext      = $this->backendUser();
            $extraUserContext = $this->frontendUser();
        } else {
            $userContext      = $this->frontendUser();
            $extraUserContext = $this->backendUser();
        }

        return [$userContext, $extraUserContext];
    }
}