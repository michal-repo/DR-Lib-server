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
     * @param int $referenceFileId The ID of the reference file to favorite.
     * @return int The ID of the newly created favorite record.
     * @throws Exception If the user is not logged in (code 401),
     *                   if the reference_file_id is invalid (code 400),
     *                   if the favorite already exists (code 409),
     *                   or if a database error occurs (code 500).
     */
    public function add(int $referenceFileId): int {
        if ($referenceFileId <= 0) {
            throw new Exception("Reference file ID must be a positive integer.", 400); // 400 Bad Request
        }

        $userId = $this->getCurrentUserID();

        $sql = "INSERT INTO favorites (user_id, reference_file_id) VALUES (:user_id, :reference_file_id)";

        try {
            $stmt = $this->db->dbh->prepare($sql);

            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':reference_file_id', $referenceFileId, PDO::PARAM_INT);

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
                throw new Exception("Could not add favorite.", 500, $e);
            }
        }
    }

    /**
     * Removes a file from the current user's favorites.
     *
     * @param int $referenceFileId The ID of the reference file to remove from favorites.
     * @return bool True if the favorite was removed, false if it didn't exist for the user.
     * @throws Exception If the user is not logged in (code 401) or if a database error occurs (code 500).
     */
    public function remove(int $referenceFileId): bool {
        if ($referenceFileId <= 0) {
            throw new Exception("Reference file ID must be a positive integer.", 400); // 400 Bad Request
        }
        $userId = $this->getCurrentUserID();

        $sql = "DELETE FROM favorites WHERE user_id = :user_id AND reference_file_id = :reference_file_id";

        try {
            $stmt = $this->db->dbh->prepare($sql);

            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':reference_file_id', $referenceFileId, PDO::PARAM_INT);

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
     * @return array An array of favorite records. Each record includes favorite details
     *               and associated reference file details (src as 'file', 'thumbnail').
     *               Returns an empty array if the user has no favorites.
     * @throws Exception If the user is not logged in (code 401) or if a database error occurs (code 500).
     */
    public function list(): array {
        $userId = $this->getCurrentUserID();

        $sql = "SELECT f.id, f.created_at, f.reference_file_id,
                       rf.src AS file, rf.thumbnail, rf.name AS file_name, rf.directory AS file_directory
                FROM favorites f
                INNER JOIN reference_files rf ON f.reference_file_id = rf.id
                WHERE f.user_id = :user_id
                ORDER BY rf.name ASC";

        try {
            $stmt = $this->db->dbh->prepare($sql);

            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);

            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception("Could not retrieve favorites.", 500, $e);
        }
    }

    /**
     * Checks if a specific file is favorited by the current user.
     *
     * @param int $referenceFileId The ID of the reference file to check.
     * @return bool True if the file is favorited by the current user, false otherwise.
     * @throws Exception If the user is not logged in (code 401) or if a database error occurs (code 500).
     */
    public function isFavorite(int $referenceFileId): bool {
        if ($referenceFileId <= 0) {
            throw new Exception("Reference file ID must be a positive integer.", 400); // 400 Bad Request
        }
        $userId = $this->getCurrentUserID();

        $sql = "SELECT COUNT(*) FROM favorites WHERE user_id = :user_id AND reference_file_id = :reference_file_id";

        try {
            $stmt = $this->db->dbh->prepare($sql);

            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':reference_file_id', $referenceFileId, PDO::PARAM_INT);

            $stmt->execute();

            $count = $stmt->fetchColumn();

            return $count > 0;
        } catch (PDOException $e) {
            throw new Exception("Could not check favorite status.", 500, $e);
        }
    }

    /**
     * Lists all unique catalogs (directories) containing favorites for the current user,
     * with pagination, optional search filtering, and includes thumbnails for files in each catalog.
     *
     * @param int $page The current page number (1-based). Defaults to 1.
     * @param int $size The number of items per page. Defaults to 20.
     * @param int $thumbnailLimit The maximum number of thumbnails to fetch per catalog. Defaults to 3.
     * @param string|null $searchQuery Optional search term to filter catalogs.
     *                                 The search applies to the directory name or file name within reference_files
     *                                 for items that are favorited by the user.
     * @return array An associative array containing 'catalogs' (list of directories with thumbnails),
     *               'total' (total number of distinct favorite directories matching the criteria),
     *               'page', 'size', 'totalPages', and 'searchQuery'.
     * @throws Exception If the user is not logged in (code 401) or if a database error occurs (code 500).
     */
    public function listFavoriteCatalogs(int $page = 1, int $size = 20, int $thumbnailLimit = 3, ?string $searchQuery = null): array {
        $userId = $this->getCurrentUserID();

        // Ensure page, size, and thumbnailLimit are positive integers
        $page = max(1, $page);
        $size = max(1, $size);
        $thumbnailLimit = max(0, $thumbnailLimit); // Allow 0 thumbnails

        // Calculate the offset for the SQL query
        $offset = ($page - 1) * $size;

        // --- Build search SQL part ---
        $searchSqlPart = "";
        $searchPattern = null;

        if ($searchQuery !== null && trim($searchQuery) !== '') {
            $searchPattern = '%' . trim($searchQuery) . '%';
            // Search in directory name or file name of the items in reference_files
            $searchSqlPart = " AND (rf.directory LIKE :search OR rf.name LIKE :search)";
        }
        // --- End search SQL part build ---

        try {
            // 1. Get the total count of distinct directories matching the criteria
            $countSql = "SELECT COUNT(DISTINCT rf.directory)
                         FROM favorites f
                         INNER JOIN reference_files rf ON f.reference_file_id = rf.id
                         WHERE f.user_id = :user_id" . $searchSqlPart;

            $countStmt = $this->db->dbh->prepare($countSql);
            $countStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            if ($searchPattern !== null) {
                $countStmt->bindParam(':search', $searchPattern, PDO::PARAM_STR);
            }
            $countStmt->execute();
            $total = $countStmt->fetchColumn();

            // 2. Get the paginated list of distinct directories matching the criteria
            $dirSql = "SELECT DISTINCT rf.directory
                       FROM favorites f
                       INNER JOIN reference_files rf ON f.reference_file_id = rf.id
                       WHERE f.user_id = :user_id" . $searchSqlPart .
                       " ORDER BY rf.directory ASC
                       LIMIT :limit OFFSET :offset";

            $dirStmt = $this->db->dbh->prepare($dirSql);
            $dirStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            if ($searchPattern !== null) {
                $dirStmt->bindParam(':search', $searchPattern, PDO::PARAM_STR);
            }
            $dirStmt->bindParam(':limit', $size, PDO::PARAM_INT);
            $dirStmt->bindParam(':offset', $offset, PDO::PARAM_INT);

            $dirStmt->execute();
            $directoriesData = $dirStmt->fetchAll(PDO::FETCH_ASSOC); // Fetches as [['directory' => 'path1'], ...]

            $catalogsWithThumbnails = [];

            // 3. Prepare statement to fetch thumbnails for each directory (if needed)
            $thumbStmt = null;
            if ($thumbnailLimit > 0 && count($directoriesData) > 0) {
                $thumbSql = "SELECT thumbnail
                             FROM reference_files
                             WHERE directory = :directory
                             ORDER BY id ASC -- Or ORDER BY name ASC
                             LIMIT :thumbnail_limit";
                $thumbStmt = $this->db->dbh->prepare($thumbSql);
                $thumbStmt->bindParam(':thumbnail_limit', $thumbnailLimit, PDO::PARAM_INT);
            }

            // 4. Loop through directories and fetch thumbnails
            foreach ($directoriesData as $dirData) {
                $directoryPath = $dirData['directory'];
                $thumbnails = [];
                if ($thumbStmt !== null) {
                    $thumbStmt->bindParam(':directory', $directoryPath, PDO::PARAM_STR);
                    $thumbStmt->execute();
                    $thumbnails = $thumbStmt->fetchAll(PDO::FETCH_COLUMN, 0);
                }
                $catalogsWithThumbnails[] = [
                    'directory' => $directoryPath,
                    'thumbnails' => $thumbnails
                ];
            }

            $totalPages = ($size > 0 && $total > 0) ? ceil($total / $size) : 0;
            if ($total == 0) { $totalPages = 0; }

            return [
                'catalogs' => $catalogsWithThumbnails,
                'total' => (int) $total,
                'page' => $page,
                'size' => $size,
                'totalPages' => (int) $totalPages,
                'searchQuery' => $searchQuery
            ];

        } catch (PDOException $e) {
            // error_log("PDOException in Favorites::listFavoriteCatalogs (paginated): " . $e->getMessage());
            throw new Exception("Could not retrieve favorite catalogs.", 500, $e);
        } catch (\Throwable $th) {
            // error_log("Unexpected error in Favorites::listFavoriteCatalogs (paginated): " . $th->getMessage());
            throw new Exception("An unexpected error occurred while listing favorite catalogs.", 500, $th);
        }
    }

    /**
     * Lists favorite files for the current user within a specific catalog, with pagination and optional search.
     *
     * @param string $catalogDirectory The directory path of the catalog.
     * @param int $page The current page number (1-based). Defaults to 1.
     * @param int $size The number of items per page. Defaults to 20.
     * @param string|null $searchQuery Optional search term to filter files by name within the catalog.
     * @return array An associative array containing 'files' (list of favorited files from the catalog),
     *               'total' (total number of matching favorite files),
     *               'page', 'size', 'totalPages', 'directoryFilter' (the catalog directory), and 'searchQuery'.
     * @throws Exception If user is not logged in (401), catalogDirectory is empty (400), or DB error (500).
     */
    public function listFavoritesByCatalog(string $catalogDirectory, int $page = 1, int $size = 20, ?string $searchQuery = null): array {
        if (empty(trim($catalogDirectory))) {
            throw new Exception("Catalog directory cannot be empty.", 400); // 400 Bad Request
        }

        $userId = $this->getCurrentUserID(); // Handles 401 if not logged in

        // Ensure page and size are positive integers
        $page = max(1, $page);
        $size = max(1, $size); // Ensure size is at least 1
        $offset = ($page - 1) * $size;

        // --- Build WHERE clause parts and parameters for search ---
        $baseWhereClauses = [
            "f.user_id = :user_id",
            "rf.directory = :catalog_directory"
        ];
        
        $searchSqlPart = "";
        $searchPattern = null;

        if ($searchQuery !== null && trim($searchQuery) !== '') {
            $searchPattern = '%' . trim($searchQuery) . '%';
            // Search on file name within the reference_files table
            $searchSqlPart = " AND (rf.name LIKE :search_pattern)"; 
        }
        
        $whereSql = " WHERE " . implode(" AND ", $baseWhereClauses) . $searchSqlPart;
        $fromSql = "FROM favorites f INNER JOIN reference_files rf ON f.reference_file_id = rf.id";

        try {
            // 1. Get the total count of matching favorite files
            $countSql = "SELECT COUNT(rf.id) " . $fromSql . $whereSql;
            $countStmt = $this->db->dbh->prepare($countSql);

            // Bind parameters for count query
            $countStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $countStmt->bindValue(':catalog_directory', $catalogDirectory, PDO::PARAM_STR);
            if ($searchPattern !== null) {
                $countStmt->bindValue(':search_pattern', $searchPattern, PDO::PARAM_STR);
            }
            $countStmt->execute();
            $total = $countStmt->fetchColumn();

            // 2. Get the paginated list of favorite files
            // Selecting all columns from reference_files, plus favorite-specific data
            $dataSql = "SELECT rf.*, f.id AS favorite_id, f.created_at AS favorited_at "
                     . $fromSql . $whereSql
                     . " ORDER BY rf.name ASC " // Consistent ordering, e.g., by file name
                     . " LIMIT :limit OFFSET :offset";
            
            $dataStmt = $this->db->dbh->prepare($dataSql);

            // Bind parameters for data query
            $dataStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $dataStmt->bindValue(':catalog_directory', $catalogDirectory, PDO::PARAM_STR);
            if ($searchPattern !== null) {
                $dataStmt->bindValue(':search_pattern', $searchPattern, PDO::PARAM_STR);
            }
            $dataStmt->bindValue(':limit', $size, PDO::PARAM_INT);
            $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $dataStmt->execute();
            $files = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            $totalPages = ($size > 0 && $total > 0) ? ceil($total / $size) : 0;
            if ($total == 0) { $totalPages = 0; }

            return [
                'files' => $files,
                'total' => (int) $total,
                'page' => $page,
                'size' => $size,
                'totalPages' => (int) $totalPages,
                'directoryFilter' => $catalogDirectory,
                'searchQuery' => $searchQuery
            ];
        } catch (PDOException $e) {
            throw new Exception("Could not retrieve favorites for the specified catalog.", 500, $e);
        } catch (\Throwable $th) {
            throw new Exception("An unexpected error occurred while listing favorites for the catalog.", 500, $th);
        }
    }
}
