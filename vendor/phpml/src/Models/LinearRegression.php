<?php
namespace PHPML\Models;

class LinearRegression {
    private $weights;
    private $bias;
    private $learningRate;
    private $iterations;
    
    public function __construct($learningRate = 0.01, $iterations = 1000) {
        $this->learningRate = $learningRate;
        $this->iterations = $iterations;
    }
    
    public function fit(array $X, array $y) {
        $numSamples = count($X);
        $numFeatures = count($X[0]);
        
        // Initialize weights and bias
        $this->weights = array_fill(0, $numFeatures, 0);
        $this->bias = 0;
        
        for ($i = 0; $i < $this->iterations; $i++) {
            $predictions = $this->predict($X);
            $errors = [];
            
            // Calculate errors
            for ($j = 0; $j < $numSamples; $j++) {
                $errors[] = $predictions[$j] - $y[$j];
            }
            
            // Update weights and bias
            for ($j = 0; $j < $numFeatures; $j++) {
                $gradient = 0;
                for ($k = 0; $k < $numSamples; $k++) {
                    $gradient += $errors[$k] * $X[$k][$j];
                }
                $this->weights[$j] -= $this->learningRate * ($gradient / $numSamples);
            }
            
            $biasGradient = array_sum($errors) / $numSamples;
            $this->bias -= $this->learningRate * $biasGradient;
        }
    }
    
    public function predict(array $X) {
        $predictions = [];
        foreach ($X as $sample) {
            $prediction = $this->bias;
            for ($i = 0; $i < count($sample); $i++) {
                $prediction += $this->weights[$i] * $sample[$i];
            }
            $predictions[] = $prediction;
        }
        return $predictions;
    }
    
    public function getWeights() {
        return $this->weights;
    }
    
    public function getBias() {
        return $this->bias;
    }
    
    public function score(array $X, array $y) {
        $predictions = $this->predict($X);
        $ssTotal = 0;
        $ssResidual = 0;
        $meanY = array_sum($y) / count($y);
        
        for ($i = 0; $i < count($y); $i++) {
            $ssTotal += pow($y[$i] - $meanY, 2);
            $ssResidual += pow($y[$i] - $predictions[$i], 2);
        }
        
        return 1 - ($ssResidual / $ssTotal);
    }
}
