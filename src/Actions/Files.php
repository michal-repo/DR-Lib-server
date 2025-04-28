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
     * Lists reference files from the 'reference_files' table with pagination.
     *
     * @param int $page The current page number (1-based). Defaults to 1.
     * @param int $size The number of items per page. Defaults to 20.
     * @return array An associative array containing 'files' (the list of files),
     *               'total' (total number of files), 'page', 'size', and 'totalPages'.
     * @throws Exception If a database error occurs.
     */
    public function listReferenceFilesPaginated(int $page = 1, int $size = 20): array
    {
        // Ensure page and size are positive integers
        $page = max(1, $page);
        $size = max(1, $size); // Ensure size is at least 1

        // Calculate the offset for the SQL query
        $offset = ($page - 1) * $size;

        try {
            // 1. Get the total count of files (needed for pagination metadata)
            // Use the database handle from BaseWithDB
            $countStmt = $this->db->dbh->query("SELECT COUNT(*) FROM reference_files");
            $total = $countStmt->fetchColumn();

            // 2. Get the paginated results
            // Using prepared statements with named parameters for safety
            $stmt = $this->db->dbh->prepare("SELECT * FROM reference_files LIMIT :limit OFFSET :offset");

            // Bind parameters, ensuring they are treated as integers
            $stmt->bindParam(':limit', $size, PDO::PARAM_INT);
            $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();

            // Fetch all results as an associative array
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate total pages
            $totalPages = ($size > 0) ? ceil($total / $size) : 0;

            // Return the data including pagination info
            return [
                'files' => $files,
                'total' => (int) $total, // Cast to int
                'page' => $page,
                'size' => $size,
                'totalPages' => (int) $totalPages // Cast to int
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
