<?php

namespace ger\fpb2cmbb;

class ext extends \phpbb\extension\base
{
    public function is_enableable()
    {
		return class_exists('\ger\feedpostbot\ext') && class_exists('ger\cmbb\cmbb\driver');
    }
}
