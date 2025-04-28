<?php

namespace DRLib\Actions;

use DRLib\Base\BaseWithDB;
use \PDO;
use \PDOException;
use \Exception; // Use the base Exception class for general errors

class Catalogs extends BaseWithDB
{
    /**
     * Constructor.
     * Inherits database connection from BaseWithDB.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Lists distinct directories (catalogs) from 'reference_files' with pagination,
     * optional search filtering, and includes thumbnails for files in each catalog.
     *
     * @param int $page The current page number (1-based). Defaults to 1.
     * @param int $size The number of items per page. Defaults to 20.
     * @param int $thumbnailLimit The maximum number of thumbnails to fetch per catalog. Defaults to 3.
     * @param string|null $searchQuery Optional search term to filter catalogs by directory or file name.
     * @return array An associative array containing 'catalogs' (list of directories with thumbnails),
     *               'total' (total number of distinct directories matching the criteria),
     *               'page', 'size', 'totalPages', and 'searchQuery'.
     * @throws Exception If a database error occurs.
     */
    public function listCatalogsPaginated(int $page = 1, int $size = 20, int $thumbnailLimit = 3, ?string $searchQuery = null): array
    {
        // Ensure page, size, and thumbnailLimit are positive integers
        $page = max(1, $page);
        $size = max(1, $size);
        $thumbnailLimit = max(0, $thumbnailLimit); // Allow 0 thumbnails

        // Calculate the offset for the SQL query
        $offset = ($page - 1) * $size;

        // --- Build WHERE clause and parameters dynamically for search ---
        $whereSql = "";
        $params = [];
        $searchPattern = null;

        if ($searchQuery !== null && trim($searchQuery) !== '') {
            // Use LIKE for partial matching in directory or name
            $whereSql = " WHERE (directory LIKE :search OR name LIKE :search)";
            $searchPattern = '%' . trim($searchQuery) . '%';
            $params[':search'] = $searchPattern;
        }
        // --- End WHERE clause build ---


        try {
            // 1. Get the total count of distinct directories matching the search criteria
            // We need to apply the WHERE clause *before* counting distinct directories
            $countSql = "SELECT COUNT(DISTINCT directory)
                         FROM reference_files" . $whereSql;
            $countStmt = $this->db->dbh->prepare($countSql);

            // Bind search parameter if it exists
            if ($searchPattern !== null) {
                $countStmt->bindParam(':search', $searchPattern, PDO::PARAM_STR);
            }
            $countStmt->execute();
            $total = $countStmt->fetchColumn();

            // 2. Get the paginated list of distinct directories matching the search criteria
            // Apply WHERE clause before GROUP BY
            $dirSql = "SELECT directory
                       FROM reference_files"
                       . $whereSql .
                       " GROUP BY directory
                       ORDER BY directory ASC
                       LIMIT :limit OFFSET :offset";
            $dirStmt = $this->db->dbh->prepare($dirSql);

            // Bind search parameter if it exists
            if ($searchPattern !== null) {
                $dirStmt->bindParam(':search', $searchPattern, PDO::PARAM_STR);
            }
            // Bind pagination parameters
            $dirStmt->bindParam(':limit', $size, PDO::PARAM_INT);
            $dirStmt->bindParam(':offset', $offset, PDO::PARAM_INT);

            $dirStmt->execute();
            $directories = $dirStmt->fetchAll(PDO::FETCH_ASSOC);

            $catalogsWithThumbnails = [];

            // 3. Prepare statement to fetch thumbnails for each directory (if needed)
            $thumbStmt = null;
            if ($thumbnailLimit > 0 && count($directories) > 0) {
                // Fetch the first N thumbnails based on file ID (or filename if preferred)
                // No need to apply search filter here again, as we already filtered the directories
                $thumbSql = "SELECT thumbnail
                             FROM reference_files
                             WHERE directory = :directory
                             ORDER BY id ASC -- Or ORDER BY name ASC
                             LIMIT :thumbnail_limit";
                $thumbStmt = $this->db->dbh->prepare($thumbSql);
                $thumbStmt->bindParam(':thumbnail_limit', $thumbnailLimit, PDO::PARAM_INT);
            }

            // 4. Loop through directories and fetch thumbnails
            foreach ($directories as $dir) {
                $thumbnails = [];
                if ($thumbStmt !== null) {
                    $thumbStmt->bindParam(':directory', $dir['directory'], PDO::PARAM_STR);
                    $thumbStmt->execute();
                    // Fetch only the thumbnail column
                    $thumbnails = $thumbStmt->fetchAll(PDO::FETCH_COLUMN, 0);
                }
                $catalogsWithThumbnails[] = [
                    'directory' => $dir['directory'],
                    'thumbnails' => $thumbnails
                ];
            }

            // Calculate total pages
            $totalPages = ($size > 0 && $total > 0) ? ceil($total / $size) : 0;
             if ($total == 0) { // Ensure totalPages is 0 if total is 0
                 $totalPages = 0;
             }

            // Return the data including pagination info and search query used
            return [
                'catalogs' => $catalogsWithThumbnails,
                'total' => (int) $total, // Cast to int
                'page' => $page,
                'size' => $size,
                'totalPages' => (int) $totalPages, // Cast to int
                'searchQuery' => $searchQuery // Include the search query used
            ];

        } catch (PDOException $e) {
            // Log the error in production, throw a generic exception
            // error_log("Database error listing catalogs: " . $e->getMessage()); // Example logging
            throw new Exception("Could not retrieve catalogs.", 500, $e); // 500 Internal Server Error
        } catch (\Throwable $th) {
             // Catch any other unexpected errors
             throw new Exception("An unexpected error occurred while listing catalogs.", 500, $th);
        }
    }
}
