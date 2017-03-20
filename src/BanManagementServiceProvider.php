<?php

/**
 * Part of the Antares Project package.
 *
 * NOTICE OF LICENSE
 *
 * Licensed under the 3-clause BSD License.
 *
 * This source file is subject to the 3-clause BSD License that is
 * bundled with this package in the LICENSE file.
 *
 * @package    Ban Management
 * @version    0.9.0
 * @author     Antares Team
 * @license    BSD License (3-clause)
 * @copyright  (c) 2017, Antares Project
 * @link       http://antaresproject.io
 */

namespace Antares\BanManagement;

use Antares\Acl\Action;
use Antares\Acl\RoleActionList;
use Antares\BanManagement\Console\Commands\BannedEmails\AddCommand;
use Antares\BanManagement\Console\Commands\BannedEmails\ListCommand;
use Antares\BanManagement\Console\Commands\BannedEmails\SyncCommand;
use Antares\BanManagement\Console\Commands\Rules\AddCommand as AddCommand2;
use Antares\BanManagement\Console\Commands\Rules\ListCommand as ListCommand2;
use Antares\BanManagement\Contracts\BannedEmailsRepositoryContract;
use Antares\BanManagement\Contracts\RulesRepositoryContract;
use Antares\BanManagement\Events\Banned;
use Antares\BanManagement\Http\Handlers\BansBreadcrumbMenu;
use Antares\BanManagement\Http\Handlers\SimpleConfig;
use Antares\BanManagement\Http\Middleware\BannedEmailMiddleware;
use Antares\BanManagement\Http\Middleware\CookieBanMiddleware;
use Antares\BanManagement\Http\Middleware\FirewallMiddleware;
use Antares\BanManagement\Http\Middleware\ThrottleRequestsMiddleware;
use Antares\BanManagement\Http\Placeholder\BansPlaceholder;
use Antares\BanManagement\Listeners\ConfigStoreListener;
use Antares\BanManagement\Listeners\CookieBanListener;
use Antares\BanManagement\Model\BannedEmail;
use Antares\BanManagement\Model\Rule;
use Antares\BanManagement\Repositories\BannedEmailsRepository;
use Antares\BanManagement\Repositories\RulesRepository;
use Antares\BanManagement\Services\RouteService;
use Antares\Foundation\Support\Factories\MenuFactory;
use Antares\Foundation\Support\Providers\ModuleServiceProvider;
use Antares\Model\Role;
use Carbon\Carbon;
use Illuminate\Routing\Router;
use M6Web\Component\Firewall\Entry\EntryFactory;
use Validator;
use Antares\Foundation\Events\SecurityFormSubmitted;
use Antares\BanManagement\Services\DDoSService;

class BanManagementServiceProvider extends ModuleServiceProvider
{

    /**
     * The application or extension namespace.
     *
     * @var string|null
     */
    protected $namespace = 'Antares\BanManagement\Http\Controllers\Admin';

    /**
     * The application or extension group namespace.
     *
     * @var string|null
     */
    protected $routeGroup = 'antares/ban_management';

    /**
     * The event handler mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        Banned::class                 => [
            CookieBanListener::class,
        ],
        'antares.form: security_form' => [
            SimpleConfig::class
        ],
        SecurityFormSubmitted::class  => [
            ConfigStoreListener::class,
        ],
    ];

    /**
     * bindable dependency injection params
     *
     * @var array
     */
    protected $di = [
        RulesRepositoryContract::class        => RulesRepository::class,
        BannedEmailsRepositoryContract::class => BannedEmailsRepository::class,
    ];

    /**
     * registering component
     */
    public function register()
    {
        parent::register();

        $this->bootMenu();

        $this->app->singleton(Config::class);

        $this->app->singleton(EntryFactory::class, function() {
            $entries = config('antares/ban_management::entries', []);
            return new EntryFactory($entries);
        });

        $this->app->singleton(RouteService::class, function() {
            $routes = config('antares/ban_management::routes', []);
            return new RouteService($routes);
        });

        $this->commands(AddCommand::class);
        $this->commands(ListCommand::class);
        $this->commands(SyncCommand::class);
        $this->commands(AddCommand2::class);
        $this->commands(ListCommand2::class);
    }

    /**
     * Boot the extension.
     *
     * @param Router $router
     */
    public function boot(Router $router)
    {
        parent::boot($router);

        $this->app->make('view')->composer('antares/ban_management::*', BansPlaceholder::class);

        if ($this->app->make(Config::class)->hasCookieTracking()) {
            $router->pushMiddlewareToGroup('web', CookieBanMiddleware::class);
        }

        $router->pushMiddlewareToGroup('web', ThrottleRequestsMiddleware::class);
        $router->pushMiddlewareToGroup('web', FirewallMiddleware::class);
        $router->pushMiddlewareToGroup('web', BannedEmailMiddleware::class);

        $this->extendValidator();
        $this->registerModelsEvents();
        $this->runDDoSProtection();
    }

    /**
     * Runs DDoS protection
     * 
     * @return DDoSService
     */
    protected function runDDoSProtection()
    {
        return $this->app->make(DDoSService::class)->run();
    }

    /**
     * Extends the validator.
     */
    protected function extendValidator()
    {
        Validator::extend('notSubmitterIpHostname', '\Antares\BanManagement\Validation\CustomRules@notSubmitterIpHostname');
        Validator::extend('notSubmitterEmail', '\Antares\BanManagement\Validation\CustomRules@notSubmitterEmail');
    }

    protected function registerModelsEvents()
    {
        $date = Carbon::today();

        Rule::saving(function(Rule $rule) use($date) {
            $rule->status = $rule->isActive($date);
        });

        BannedEmail::saving(function(BannedEmail $rule) use($date) {
            $rule->status = $rule->isActive($date);
        });
    }

    /**
     * top left menu
     */
    protected function bootMenu()
    {
        $menuFactory = $this->app->make(MenuFactory::class);
        $handlers    = [BansBreadcrumbMenu::class];
        $menuFactory->with('menu.top.ban_management')
                ->withHandlers($handlers)
                ->compose('antares/ban_management::admin.list', ['name' => 'ban_management', 'title' => 'antares/ban_management::title.menu.add']);
    }

    /**
     * Returns the collection of the ACL actions.
     *
     * @return RoleActionList
     */
    public static function acl()
    {
        $actions = [
            new Action('admin.ban_management.rules.index', 'List Rules'),
            new Action('admin.ban_management.rules.create', 'Add Rule'),
            new Action('admin.ban_management.rules.store', 'Add Rule'),
            new Action('admin.ban_management.rules.edit', 'Update Rule'),
            new Action('admin.ban_management.rules.update', 'Update Rule'),
            new Action('admin.ban_management.rules.destroy', 'Delete Rule'),
            new Action('admin.ban_management.bannedemails.index', 'List Banned Emails'),
            new Action('admin.ban_management.bannedemails.create', 'Add Banned Email'),
            new Action('admin.ban_management.bannedemails.store', 'Add Banned Email'),
            new Action('admin.ban_management.bannedemails.edit', 'Update Banned Email'),
            new Action('admin.ban_management.bannedemails.update', 'Update Banned Email'),
            new Action('admin.ban_management.bannedemails.destroy', 'Delete Banned Email'),
        ];

        $adminActions = array_merge($actions, [
            new Action('admin.ban_management.configuration', 'Configuration'),
        ]);

        $permissions = new RoleActionList;
        $permissions->add(Role::admin()->name, $adminActions);
        $permissions->add(Role::member()->name, $actions);

        return $permissions;
    }

}