<?php
namespace PHPML;

/**
 * PHP Machine Learning Library
 * A lightweight machine learning library for data analysis
 */
class ML {
    private static $instance;
    
    private function __construct() {}
    
    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getVersion() {
        return '1.0.0';
    }
    
    public function getAvailableModels() {
        return [
            'LinearRegression',
            'LogisticRegression',
            'KMeans',
            'DecisionTree',
            'RandomForest'
        ];
    }
}
