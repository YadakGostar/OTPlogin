<?php
/**
 * @package     Otplogin
 * @license     GNU General Public License version 2 or later
 
 * @copyright (C) 2026 Reza Esfandiari
 * @author    Reza Esfandiari
 * @link      https://www.yadakgostar.co*/

namespace Otplogin\Component\Otplogin\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;

/** The component has no front-end pages; visiting it directly goes to the home page. */
class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = [])
    {
        $this->app->redirect(Uri::root());

        return $this;
    }
}
