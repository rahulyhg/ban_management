<?php

/**
 * Part of the Antares package.
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
 * @copyright  (c) 2017, Antares
 * @link       http://antaresproject.io
 */

namespace Antares\Modules\BanManagement\Events;

use Antares\Modules\BanManagement\Contracts\RuleContract;
use Illuminate\Http\Request;

class Banned
{

    /**
     * Rule instance.
     *
     * @var RuleContract
     */
    protected $rule;

    /**
     * Request instance.
     *
     * @var Request
     */
    protected $request;

    /**
     * Banned constructor.
     * @param RuleContract $rule
     * @param Request $request
     */
    public function __construct(RuleContract $rule, Request $request)
    {
        $this->rule    = $rule;
        $this->request = $request;
    }

    /**
     * Returns the rule instance.
     *
     * @return RuleContract
     */
    public function getRule()
    {
        return $this->rule;
    }

    /**
     * Returns the request instance.
     *
     * @return Request
     */
    public function getRequest()
    {
        return $this->request;
    }

}
