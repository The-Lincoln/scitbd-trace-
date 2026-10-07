<?php
namespace PHPML\Models;

class KMeans {
    private $k;
    private $maxIterations;
    private $centroids;
    
    public function __construct($k = 3, $maxIterations = 100) {
        $this->k = $k;
        $this->maxIterations = $maxIterations;
    }
    
    public function fit(array $data) {
        $numFeatures = count($data[0]);
        
        // Initialize centroids randomly
        $this->centroids = [];
        $indices = array_rand($data, $this->k);
        foreach ($indices as $index) {
            $this->centroids[] = $data[$index];
        }
        
        for ($iteration = 0; $iteration < $this->maxIterations; $iteration++) {
            $clusters = $this->assignClusters($data);
            $newCentroids = $this->updateCentroids($data, $clusters);
            
            // Check for convergence
            if ($this->centroidsConverged($newCentroids)) {
                break;
            }
            
            $this->centroids = $newCentroids;
        }
        
        return $this->centroids;
    }
    
    private function assignClusters(array $data) {
        $clusters = [];
        foreach ($data as $index => $point) {
            $minDistance = INF;
            $cluster = 0;
            
            foreach ($this->centroids as $centroidIndex => $centroid) {
                $distance = $this->calculateDistance($point, $centroid);
                if ($distance < $minDistance) {
                    $minDistance = $distance;
                    $cluster = $centroidIndex;
                }
            }
            
            $clusters[$index] = $cluster;
        }
        
        return $clusters;
    }
    
    private function updateCentroids(array $data, array $clusters) {
        $newCentroids = [];
        $clusterCounts = array_fill(0, $this->k, 0);
        
        // Initialize new centroids
        for ($i = 0; $i < $this->k; $i++) {
            $newCentroids[$i] = array_fill(0, count($data[0]), 0);
        }
        
        // Sum points in each cluster
        foreach ($clusters as $pointIndex => $cluster) {
            for ($feature = 0; $feature < count($data[0]); $feature++) {
                $newCentroids[$cluster][$feature] += $data[$pointIndex][$feature];
            }
            $clusterCounts[$cluster]++;
        }
        
        // Calculate new centroids
        for ($i = 0; $i < $this->k; $i++) {
            if ($clusterCounts[$i] > 0) {
                for ($feature = 0; $feature < count($data[0]); $feature++) {
                    $newCentroids[$i][$feature] /= $clusterCounts[$i];
                }
            }
        }
        
        return $newCentroids;
    }
    
    private function centroidsConverged(array $newCentroids) {
        for ($i = 0; $i < $this->k; $i++) {
            if ($this->calculateDistance($this->centroids[$i], $newCentroids[$i]) > 0.001) {
                return false;
            }
        }
        return true;
    }
    
    private function calculateDistance(array $point1, array $point2) {
        $sum = 0;
        for ($i = 0; $i < count($point1); $i++) {
            $sum += pow($point1[$i] - $point2[$i], 2);
        }
        return sqrt($sum);
    }
    
    public function predict(array $data) {
        return $this->assignClusters($data);
    }
    
    public function getCentroids() {
        return $this->centroids;
    }
}
