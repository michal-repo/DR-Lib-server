<?php

namespace DRLib\Actions;

use DRLib\Auth\APIAuth;
use DRLib\Base\BaseWithDB;
use \PDO;
use \PDOException;
use \Exception; // Use the base Exception class for general errors

class Files extends BaseWithDB
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
     * Retrieves the ID of the currently authenticated user.
     * Returns null if the user is not logged in.
     *
     * @return int|null The ID of the currently logged-in user, or null.
     */
    private function getCurrentUserID(): ?int
    {
        $auth = new APIAuth();
        return $auth->getUserId(); // Returns int or null
    }

    /**
     * Lists reference files from the 'reference_files' table with pagination and optional directory filter.
     * Includes an 'is_favorite' flag for each file based on the current user.
     *
     * @param int $page The current page number (1-based). Defaults to 1.
     * @param int $size The number of items per page. Defaults to 20.
     * @param string|null $directory Optional directory to filter by. Defaults to null (no filter).
     * @return array An associative array containing 'files' (the list of files),
     *               'total' (total number of files), 'page', 'size', 'totalPages', and 'directoryFilter'.
     * @throws Exception If a database error occurs.
     */
    public function listReferenceFilesPaginated(int $page = 1, int $size = 20, ?string $directory = null): array
    {
        // Ensure page and size are positive integers
        $page = max(1, $page);
        $size = max(1, $size); // Ensure size is at least 1

        // Calculate the offset for the SQL query
        $offset = ($page - 1) * $size;

        $currentUserId = $this->getCurrentUserID();

        // --- Build WHERE clause and parameters dynamically ---
        $whereClauses = [];
        $params = [];

        if ($directory !== null && $directory !== '') {
            $whereClauses[] = "directory = :directory";
            $params[':directory'] = $directory;
        }

        $whereSql = !empty($whereClauses) ? " WHERE " . implode(" AND ", $whereClauses) : "";
        // --- End WHERE clause build ---

        try {
            // 1. Get the total count of files (with filter applied)
            $countSql = "SELECT COUNT(*) FROM reference_files" . $whereSql;
            $countStmt = $this->db->dbh->prepare($countSql);

            // Bind parameters for count query
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value); // Use bindValue for simplicity here
            }
            $countStmt->execute();
            $total = $countStmt->fetchColumn();

            // 2. Get the paginated results (with filter applied)
            // Added is_favorite flag and ORDER BY for consistent pagination results
            $selectSql = "SELECT rf.*, (fav.id IS NOT NULL) AS is_favorite
                          FROM reference_files rf
                          LEFT JOIN favorites fav ON rf.id = fav.reference_file_id AND fav.user_id = :current_user_id"
                         . $whereSql . " ORDER BY rf.id DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $this->db->dbh->prepare($selectSql);

            // Bind parameters for select query (filter + pagination)
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            // Bind current_user_id for the LEFT JOIN condition
            if ($currentUserId !== null) {
                $stmt->bindValue(':current_user_id', $currentUserId, PDO::PARAM_INT);
            } else {
                // If no user is logged in, bind NULL. The SQL condition fav.user_id = NULL will result in is_favorite = false.
                $stmt->bindValue(':current_user_id', null, PDO::PARAM_NULL);
            }

            $stmt->bindParam(':limit', $size, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);


            $stmt->execute();

            // Fetch all results as an associative array
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate total pages
            // Explicitly cast is_favorite to boolean
            foreach ($files as &$file) { // Use reference to modify array directly
                if (isset($file['is_favorite'])) {
                    $file['is_favorite'] = (bool)$file['is_favorite'];
                }
            }
            unset($file); // Unset reference to last element

            $totalPages = ($size > 0 && $total > 0) ? ceil($total / $size) : 0;
            if ($total == 0) { // Ensure totalPages is 0 if total is 0
                $totalPages = 0;
            }


            // Return the data including pagination info and the applied filter
            return [
                'files' => $files,
                'total' => (int) $total, // Cast to int
                'page' => $page,
                'size' => $size,
                'totalPages' => (int) $totalPages, // Cast to int
                'directoryFilter' => $directory // Include the filter used in the response
            ];

        } catch (PDOException $e) {
            // Log the error in production, throw a generic exception
            // error_log("Database error listing reference files: " . $e->getMessage()); // Example logging
            // Throw an exception that handleErr can catch
            throw new Exception("Could not retrieve reference files.", 500, $e); // 500 Internal Server Error
        } catch (\Throwable $th) {
             // Catch any other unexpected errors
             throw new Exception("An unexpected error occurred while listing files.", 500, $th);
        }
    }

    // You can add other methods related to reference files here (e.g., getById, search)
}
