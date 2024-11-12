<?php

namespace StoutLogic\AcfBuilder\Traits;

trait CanSingularize
{
    /**
     * Return a singularized string.
     * @param  string $value
     * @return string
     */
    protected function singularize($value)
    {
        if (class_exists('\Doctrine\Inflector\InflectorFactory')) {
            return \Doctrine\Inflector\InflectorFactory::create()->build()->singularize($value);
        }

        if (class_exists('\Doctrine\Common\Inflector\Inflector')) {
            return \Doctrine\Common\Inflector\Inflector::singularize($value);
        }

        return preg_replace("/s$/", "", $value);
    }
}
