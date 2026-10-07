<?php
namespace PHPML\Evaluation;

class ModelEvaluator {
    public static function accuracy(array $trueLabels, array $predictedLabels) {
        if (count($trueLabels) !== count($predictedLabels)) {
            throw new \InvalidArgumentException("Arrays must have the same length");
        }
        
        $correct = 0;
        for ($i = 0; $i < count($trueLabels); $i++) {
            if ($trueLabels[$i] == $predictedLabels[$i]) {
                $correct++;
            }
        }
        
        return $correct / count($trueLabels);
    }
    
    public static function precision(array $trueLabels, array $predictedLabels, $positiveClass) {
        $truePositives = 0;
        $falsePositives = 0;
        
        for ($i = 0; $i < count($trueLabels); $i++) {
            if ($predictedLabels[$i] == $positiveClass) {
                if ($trueLabels[$i] == $positiveClass) {
                    $truePositives++;
                } else {
                    $falsePositives++;
                }
            }
        }
        
        if ($truePositives + $falsePositives == 0) {
            return 0;
        }
        
        return $truePositives / ($truePositives + $falsePositives);
    }
    
    public static function recall(array $trueLabels, array $predictedLabels, $positiveClass) {
        $truePositives = 0;
        $falseNegatives = 0;
        
        for ($i = 0; $i < count($trueLabels); $i++) {
            if ($trueLabels[$i] == $positiveClass) {
                if ($predictedLabels[$i] == $positiveClass) {
                    $truePositives++;
                } else {
                    $falseNegatives++;
                }
            }
        }
        
        if ($truePositives + $falseNegatives == 0) {
            return 0;
        }
        
        return $truePositives / ($truePositives + $falseNegatives);
    }
    
    public static function f1Score(array $trueLabels, array $predictedLabels, $positiveClass) {
        $precision = self::precision($trueLabels, $predictedLabels, $positiveClass);
        $recall = self::recall($trueLabels, $predictedLabels, $positiveClass);
        
        if ($precision + $recall == 0) {
            return 0;
        }
        
        return 2 * ($precision * $recall) / ($precision + $recall);
    }
    
    public static function confusionMatrix(array $trueLabels, array $predictedLabels) {
        $classes = array_unique(array_merge($trueLabels, $predictedLabels));
        $matrix = [];
        
        foreach ($classes as $class) {
            $matrix[$class] = [];
            foreach ($classes as $class2) {
                $matrix[$class][$class2] = 0;
            }
        }
        
        for ($i = 0; $i < count($trueLabels); $i++) {
            $matrix[$trueLabels[$i]][$predictedLabels[$i]]++;
        }
        
        return $matrix;
    }
    
    public static function meanSquaredError(array $trueValues, array $predictedValues) {
        if (count($trueValues) !== count($predictedValues)) {
            throw new \InvalidArgumentException("Arrays must have the same length");
        }
        
        $sum = 0;
        for ($i = 0; $i < count($trueValues); $i++) {
            $sum += pow($trueValues[$i] - $predictedValues[$i], 2);
        }
        
        return $sum / count($trueValues);
    }
    
    public static function meanAbsoluteError(array $trueValues, array $predictedValues) {
        if (count($trueValues) !== count($predictedValues)) {
            throw new \InvalidArgumentException("Arrays must have the same length");
        }
        
        $sum = 0;
        for ($i = 0; $i < count($trueValues); $i++) {
            $sum += abs($trueValues[$i] - $predictedValues[$i]);
        }
        
        return $sum / count($trueValues);
    }
}
