<?php
/**
 * Created by PhpStorm.
 * User: tolmachyov
 * Date: 18.03.16
 * Time: 22:05
 */

namespace PhpDaemon;


interface Job {
    public function __invoke();
}