<?php
namespace PHPML\Analysis;

class DataAnalyzer {
    private $data;
    
    public function __construct(array $data) {
        $this->data = $data;
    }
    
    public function getSummary() {
        $summary = [];
        foreach ($this->data as $columnIndex => $column) {
            $values = array_column($this->data, $columnIndex);
            $cleanValues = array_filter($values, function($value) {
                return $value !== null && $value !== '';
            });
            
            $summary[$columnIndex] = [
                'count' => count($cleanValues),
                'mean' => array_sum($cleanValues) / count($cleanValues),
                'std' => $this->calculateStdDev($cleanValues),
                'min' => min($cleanValues),
                'max' => max($cleanValues),
                'median' => $this->calculateMedian($cleanValues),
                'mode' => $this->calculateMode($cleanValues)
            ];
        }
        return $summary;
    }
    
    public function correlationMatrix() {
        $numColumns = count($this->data[0]);
        $matrix = [];
        
        for ($i = 0; $i < $numColumns; $i++) {
            $matrix[$i] = [];
            for ($j = 0; $j < $numColumns; $j++) {
                $matrix[$i][$j] = $this->calculateCorrelation(
                    array_column($this->data, $i),
                    array_column($this->data, $j)
                );
            }
        }
        
        return $matrix;
    }
    
    private function calculateCorrelation(array $x, array $y) {
        $cleanX = array_filter($x, function($value) {
            return $value !== null && $value !== '';
        });
        $cleanY = array_filter($y, function($value) {
            return $value !== null && $value !== '';
        });
        
        if (count($cleanX) !== count($cleanY)) {
            return 0;
        }
        
        $n = count($cleanX);
        $sumX = array_sum($cleanX);
        $sumY = array_sum($cleanY);
        $sumXY = 0;
        $sumX2 = 0;
        $sumY2 = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $cleanX[$i] * $cleanY[$i];
            $sumX2 += pow($cleanX[$i], 2);
            $sumY2 += pow($cleanY[$i], 2);
        }
        
        $numerator = $n * $sumXY - $sumX * $sumY;
        $denominator = sqrt(($n * $sumX2 - pow($sumX, 2)) * ($n * $sumY2 - pow($sumY, 2)));
        
        if ($denominator == 0) {
            return 0;
        }
        
        return $numerator / $denominator;
    }
    
    private function calculateStdDev(array $data) {
        $mean = array_sum($data) / count($data);
        $variance = array_sum(array_map(function($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $data)) / count($data);
        return sqrt($variance);
    }
    
    private function calculateMedian(array $data) {
        sort($data);
        $count = count($data);
        $middle = floor(($count - 1) / 2);
        if ($count % 2) {
            return $data[$middle];
        }
        return ($data[$middle] + $data[$middle + 1]) / 2;
    }
    
    private function calculateMode(array $data) {
        $values = array_count_values($data);
        arsort($values);
        return key($values);
    }
    
    public function detectOutliers($columnIndex, $threshold = 1.5) {
        $values = array_column($this->data, $columnIndex);
        $cleanValues = array_filter($values, function($value) {
            return $value !== null && $value !== '';
        });
        
        $q1 = $this->calculatePercentile($cleanValues, 25);
        $q3 = $this->calculatePercentile($cleanValues, 75);
        $iqr = $q3 - $q1;
        $lowerBound = $q1 - $threshold * $iqr;
        $upperBound = $q3 + $threshold * $iqr;
        
        $outliers = [];
        foreach ($values as $index => $value) {
            if ($value < $lowerBound || $value > $upperBound) {
                $outliers[] = $index;
            }
        }
        
        return $outliers;
    }
    
    private function calculatePercentile(array $data, $percentile) {
        sort($data);
        $index = ($percentile / 100) * (count($data) - 1);
        $lower = floor($index);
        $upper = ceil($index);
        $weight = $index - $lower;
        
        if ($lower == $upper) {
            return $data[$lower];
        }
        
        return $data[$lower] + $weight * ($data[$upper] - $data[$lower]);
    }
}
