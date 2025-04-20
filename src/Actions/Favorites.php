<?php

namespace DRLib\Actions;

use DRLib\Base\BaseWithDB;
use DRLib\Auth\APIAuth;
use \PDO;
use \PDOException;
use \Exception;


class Favorites extends BaseWithDB {

    public function __construct() {
        parent::__construct();
    }

    /**
     * Retrieves the ID of the currently authenticated user.
     * Creates a new APIAuth instance to check login status.
     *
     * @return int The ID of the currently logged-in user.
     * @throws Exception If the user is not logged in (code 401).
     */
    private function getCurrentUserID(): int {
        $auth = new APIAuth();
        $userId = $auth->getUserId();

        if ($userId === null) {
            // User is not logged in, throw an exception
            throw new Exception("User not logged in.", 401); // 401 Unauthorized
        }

        // If we reach here, $userId is an integer
        return $userId;
    }

    /**
     * Adds a file to the current user's favorites.
     *
     * @param string $filePath The path or identifier of the file to favorite.
     * @return int The ID of the newly created favorite record.
     * @throws Exception If the user is not logged in (code 401),
     *                   if the favorite already exists (code 409),
     *                   or if a database error occurs (code 500).
     */
    public function add(string $filePath): int {
        // Get the user ID internally
        $userId = $this->getCurrentUserID();

        $sql = "INSERT INTO favorites (user_id, file) VALUES (:user_id, :file)";

        try {
            $stmt = $this->db->dbh->prepare($sql);

            // Use the internally retrieved userId
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':file', $filePath, PDO::PARAM_STR);

            $stmt->execute();

            $lastId = $this->db->dbh->lastInsertId();
            if ($lastId === false) {
                throw new Exception("Failed to retrieve last insert ID for favorite.", 500);
            }
            return (int)$lastId;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                throw new Exception("This file is already in favorites for this user.", 409, $e);
            } else {
                // error_log("PDOException in Favorites::add: " . $e->getMessage());
                throw new Exception("Could not add favorite.", 500, $e);
            }
        }
    }

    /**
     * Removes a file from the current user's favorites.
     *
     * @param string $filePath The path or identifier of the file to remove.
     * @return bool True if the favorite was removed, false if it didn't exist for the user.
     * @throws Exception If the user is not logged in (code 401) or if a database error occurs (code 500).
     */
    public function remove(string $filePath): bool {
        $userId = $this->getCurrentUserID();

        $sql = "DELETE FROM favorites WHERE user_id = :user_id AND file = :file";

        try {
            $stmt = $this->db->dbh->prepare($sql);

            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':file', $filePath, PDO::PARAM_STR);

            $stmt->execute();

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            // error_log("PDOException in Favorites::remove: " . $e->getMessage());
            throw new Exception("Could not remove favorite.", 500, $e);
        }
    }

    /**
     * Lists all favorites for the current user.
     *
     * @return array An array of favorite records (each an associative array with 'id', 'file', 'created_at').
     *               Returns an empty array if the user has no favorites.
     * @throws Exception If the user is not logged in (code 401) or if a database error occurs (code 500).
     */
    public function list(): array {
        $userId = $this->getCurrentUserID();

        $sql = "SELECT id, file, created_at
                FROM favorites
                WHERE user_id = :user_id
                ORDER BY file DESC";

        try {
            $stmt = $this->db->dbh->prepare($sql);

            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);

            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // error_log("PDOException in Favorites::list: " . $e->getMessage());
            throw new Exception("Could not retrieve favorites.", 500, $e);
        }
    }

    /**
     * Checks if a specific file is favorited by the current user.
     *
     * @param string $filePath The path or identifier of the file to check.
     * @return bool True if the file is favorited by the current user, false otherwise.
     * @throws Exception If the user is not logged in (code 401) or if a database error occurs (code 500).
     */
    public function isFavorite(string $filePath): bool {
        $userId = $this->getCurrentUserID();

        $sql = "SELECT COUNT(*) FROM favorites WHERE user_id = :user_id AND file = :file";

        try {
            $stmt = $this->db->dbh->prepare($sql);

            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':file', $filePath, PDO::PARAM_STR);

            $stmt->execute();

            $count = $stmt->fetchColumn();

            return $count > 0;
        } catch (PDOException $e) {
            // error_log("PDOException in Favorites::isFavorite: " . $e->getMessage());
            throw new Exception("Could not check favorite status.", 500, $e);
        }
    }
}
