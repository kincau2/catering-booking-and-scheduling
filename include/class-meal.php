<?php

class Meal {
    private $id;
    private $title;
    private $description;
    private $photo;
    private $sku;
    private $date_created_gmt;

    // Modified constructor: fetches and assigns data from the catering_meal table
    public function __construct($id = null) {
        global $wpdb;
        $this->id = $id;
        if ($id) {
            $table_meal = $wpdb->prefix . 'catering_meal';
            $meal = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $table_meal WHERE ID = %d", $id),
                ARRAY_A
            );
            if ($meal) {
                $this->title            = $meal['title'];
                $this->description      = $meal['description'];
                $this->photo            = $meal['photo'];
                $this->sku              = $meal['sku'];
                $this->date_created_gmt = $meal['date_created_gmt'];
            }
        }
    }

    // Getter and Setter for ID
    public function getID() {
        return $this->id;
    }

    // Getter and Setter for Title
    public function getTitle() {
        return $this->title;
    }

    // Getter and Setter for Description
    public function getDescription() {
        return $this->description;
    }

    // Getter and Setter for Photo
    public function getPhoto() {
        return $this->photo;
    }

    // Getter and Setter for SKU
    public function getSku() {
        return $this->sku;
    }

    // Getter and Setter for Date Created GMT
    public function getDateCreatedGmt() {
        return $this->date_created_gmt;
    }

}

?>