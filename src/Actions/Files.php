<?php

namespace DRLib\Actions;

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
     * Lists reference files from the 'reference_files' table with pagination and optional directory filter.
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
            // Added ORDER BY for consistent pagination results
            $selectSql = "SELECT * FROM reference_files" . $whereSql . " ORDER BY id DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->db->dbh->prepare($selectSql);

            // Bind parameters for select query (filter + pagination)
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindParam(':limit', $size, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();

            // Fetch all results as an associative array
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate total pages
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
