<?php
/**
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information please see
 * <http://phing.info>.
 */

/**
 * Executes a series of SQL statements on a database using PDO.
 *
 * <p>Statements can
 * either be read in from a text file using the <i>src</i> attribute or from
 * between the enclosing SQL tags.</p>
 *
 * <p>Multiple statements can be provided, separated by semicolons (or the
 * defined <i>delimiter</i>). Individual lines within the statements can be
 * commented using either --, // or REM at the start of the line.</p>
 *
 * <p>The <i>autocommit</i> attribute specifies whether auto-commit should be
 * turned on or off whilst executing the statements. If auto-commit is turned
 * on each statement will be executed and committed. If it is turned off the
 * statements will all be executed as one transaction.</p>
 *
 * <p>The <i>onerror</i> attribute specifies how to proceed when an error occurs
 * during the execution of one of the statements.
 * The possible values are: <b>continue</b> execution, only show the error;
 * <b>stop</b> execution and commit transaction;
 * and <b>abort</b> execution and transaction and fail task.</p>
 *
 * This task can also be used as a Condition.
 *
 * @author  Hans Lellelid <hans@xmpl.org> (Phing)
 * @author  Jeff Martin <jeff@custommonkey.org> (Ant)
 * @author  Michael McCallum <gholam@xtra.co.nz> (Ant)
 * @author  Tim Stephenson <tim.stephenson@sybase.com> (Ant)
 * @package phing.tasks.ext.pdo
 */
class PDOSQLExecTask extends PDOTask implements Condition
{
    /**
     * Count of how many statements were executed successfully.
     *
     * @var int
     */
    private $goodSql = 0;

    /**
     * Count of total number of SQL statements.
     *
     * @var int
     */
    private $totalSql = 0;

    const DELIM_ROW = "row";
    const DELIM_NORMAL = "normal";
    const DELIM_NONE = "none";

    /**
     * Database connection
     *
     * @var PDO
     */
    private $conn = null;

    /**
     * Files to load
     *
     * @var FileSet[]
     */
    private $filesets = [];

    /**
     * Files to load
     *
     * @var FileList[]
     */
    private $filelists = [];

    /**
     * Formatter elements.
     *
     * @var PDOSQLExecFormatterElement[]
     */
    private $formatters = [];

    /**
     * SQL statement
     *
     * @var PDOStatement
     */
    private $statement;

    /**
     * SQL input file
     *
     * @var PhingFile
     */
    private $srcFile;

    /**
     * SQL input command
     *
     * @var string
     */
    private $sqlCommand = "";

    /**
     * SQL transactions to perform
     */
    private $transactions = [];

    /**
     * SQL Statement delimiter (for parsing files)
     *
     * @var string
     */
    private $delimiter = ";";

    /**
     * The delimiter type indicating whether the delimiter will
     * only be recognized on a line by itself
     */
    private $delimiterType = self::DELIM_NONE;

    /**
     * Action to perform if an error is found
     **/
    private $onError = "abort";

    /**
     * Encoding to use when reading SQL statements from a file
     */
    private $encoding = null;

    /**
     * Fetch mode for PDO select queries.
     *
     * @var int
     */
    private $fetchMode;

    /**
     * Set the name of the SQL file to be run.
     * Required unless statements are enclosed in the build file
     *
     * @param PhingFile $srcFile
     */
    public function setSrc(PhingFile $srcFile)
    {
        $this->srcFile = $srcFile;
    }

    /**
     * Set an inline SQL command to execute.
     * NB: Properties are not expanded in this text.
     *
     * @param $sql
     */
    public function addText($sql)
    {
        $this->sqlCommand .= $sql;
    }

    /**
     * Adds a set of files (nested fileset attribute).
     *
     * @param FileSet $set
     */
    public function addFileset(FileSet $set)
    {
        $this->filesets[] = $set;
    }

    /**
     * Adds a set of files (nested filelist attribute).
     *
     * @param FileList $list
     */
    public function addFilelist(FileList $list)
    {
        $this->filelists[] = $list;
    }

    /**
     * Creates a new PDOSQLExecFormatterElement for <formatter> element.
     *
     * @return PDOSQLExecFormatterElement
     */
    public function createFormatter()
    {
        $fe = new PDOSQLExecFormatterElement($this);
        $this->formatters[] = $fe;

        return $fe;
    }

    /**
     * Add a SQL transaction to execute
     */
    public function createTransaction()
    {
        $t = new PDOSQLExecTransaction($this);
        $this->transactions[] = $t;

        return $t;
    }

    /**
     * Set the file encoding to use on the SQL files read in
     *
     * @param string $encoding the encoding to use on the files
     */
    public function setEncoding($encoding)
    {
        $this->encoding = $encoding;
    }

    /**
     * Set the statement delimiter.
     *
     * <p>For example, set this to "go" and delimitertype to "ROW" for
     * Sybase ASE or MS SQL Server.</p>
     *
     * @param string $delimiter
     */
    public function setDelimiter(string $delimiter): void
    {
        $this->delimiter = $delimiter;
    }

    /**
     * Get the statement delimiter.
     *
     * @return string
     */
    public function getDelimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * Set the Delimiter type for this sql task. The delimiter type takes two
     * values - normal and row. Normal means that any occurrence of the delimiter
     * terminate the SQL command whereas with row, only a line containing just
     * the delimiter is recognized as the end of the command.
     *
     * @param string $delimiterType
     */
    public function setDelimiterType(string $delimiterType): void
    {
        $this->delimiterType = $delimiterType;
    }

    /**
     * Action to perform when statement fails: continue, stop, or abort
     * optional; default &quot;abort&quot;
     *
     * @param string $action continue|stop|abort
     */
    public function setOnerror($action): void
    {
        $this->onError = $action;
    }

    /**
     * Sets the fetch mode to use for the PDO resultset.
     *
     * @param  mixed $mode The PDO fetchmode integer or constant name.
     * @throws BuildException
     */
    public function setFetchmode($mode): void
    {
        if (is_numeric($mode)) {
            $this->fetchMode = (int) $mode;
        } else {
            if (defined($mode)) {
                $this->fetchMode = constant($mode);
            } else {
                throw new BuildException("Invalid PDO fetch mode specified: " . $mode, $this->getLocation());
            }
        }
    }

    /**
     * Gets a default output writer for this task.
     *
     * @return Writer
     */
    private function getDefaultOutput()
    {
        return new LogWriter($this);
    }

    /**
     * Load the sql file and then execute it.
     *
     * {@inheritdoc}
     *
     * @throws BuildException
     */
    public function main()
    {

        // Set a default fetchmode if none was specified
        // (We're doing that here to prevent errors loading the class is PDO is not available.)
        if ($this->fetchMode === null) {
            $this->fetchMode = PDO::FETCH_ASSOC;
        }

        // Initialize the formatters here.  This ensures that any parameters passed to the formatter
        // element get passed along to the actual formatter object
        foreach ($this->formatters as $fe) {
            $fe->prepare($this->getLocation());
        }

        $savedTransaction = [];
        for ($i = 0, $size = count($this->transactions); $i < $size; $i++) {
            $savedTransaction[] = clone $this->transactions[$i];
        }

        $savedSqlCommand = $this->sqlCommand;

        $this->sqlCommand = trim($this->sqlCommand);

        try {
            if (
                $this->srcFile === null
                && $this->sqlCommand === ""
                && empty($this->filesets)
                && empty($this->filelists)
                && count($this->transactions) === 0
            ) {
                throw new BuildException(
                    "Source file or fileset/filelist, "
                    . "transactions or sql statement "
                    . "must be set!",
                    $this->getLocation()
                );
            }

            if ($this->srcFile !== null && !$this->srcFile->exists()) {
                throw new BuildException("Source file does not exist!", $this->getLocation());
            }

            // deal with the filesets
            foreach ($this->filesets as $fs) {
                $ds = $fs->getDirectoryScanner($this->project);
                $srcDir = $fs->getDir($this->project);
                $srcFiles = $ds->getIncludedFiles();
                // Make a transaction for each file
                foreach ($srcFiles as $srcFile) {
                    $t = $this->createTransaction();
                    $t->setSrc(new PhingFile($srcDir, $srcFile));
                }
            }

            // process filelists
            foreach ($this->filelists as $fl) {
                $srcDir = $fl->getDir($this->project);
                $srcFiles = $fl->getFiles($this->project);
                // Make a transaction for each file
                foreach ($srcFiles as $srcFile) {
                    $t = $this->createTransaction();
                    $t->setSrc(new PhingFile($srcDir, $srcFile));
                }
            }

            // Make a transaction group for the outer command
            $t = $this->createTransaction();
            if ($this->srcFile) {
                $t->setSrc($this->srcFile);
            }
            $t->addText($this->sqlCommand);
            $this->conn = $this->getConnection();

            try {
                $this->statement = null;

                // Initialize the formatters.
                $this->initFormatters();

                try {
                    // Process all transactions
                    for ($i = 0, $size = count($this->transactions); $i < $size; $i++) {
                        if (!$this->isAutocommit()) {
                            $this->log("Beginning transaction", Project::MSG_VERBOSE);
                            $this->conn->beginTransaction();
                        }
                        $this->transactions[$i]->runTransaction();
                        if (!$this->isAutocommit()) {
                            $this->log("Committing transaction", Project::MSG_VERBOSE);
                            $this->conn->commit();
                        }
                    }
                } catch (Exception $e) {
                    $this->closeConnection();
                    throw $e;
                }
            } catch (IOException $e) {
                if (!$this->isAutocommit() && $this->conn !== null && $this->onError == "abort") {
                    try {
                        $this->conn->rollback();
                    } catch (PDOException $ex) {
                    }
                }
                $this->closeConnection();
                throw new BuildException($e->getMessage(), $this->getLocation());
            } catch (PDOException $e) {
                if (!$this->isAutocommit() && $this->conn !== null && $this->onError == "abort") {
                    try {
                        $this->conn->rollback();
                    } catch (PDOException $ex) {
                    }
                }
                $this->closeConnection();
                throw new BuildException($e->getMessage(), $this->getLocation());
            }

            // Close the formatters.
            $this->closeFormatters();

            $this->log(
                $this->goodSql . " of " . $this->totalSql .
                " SQL statements executed successfully"
            );
        } catch (Exception $e) {
            throw $e;
        } finally {
            $this->transactions = $savedTransaction;
            $this->sqlCommand = $savedSqlCommand;
            $this->closeConnection();
        }
    }

    /**
     * read in lines and execute them
     *
     * @param  Reader $reader
     * @throws BuildException
     */
    public function runStatements(Reader $reader)
    {
        if (self::DELIM_NONE == $this->delimiterType) {
            $splitter = new DummyPDOQuerySplitter($this, $reader);
        } elseif (self::DELIM_NORMAL == $this->delimiterType && 0 === strpos($this->getUrl(), 'pgsql:')) {
            $splitter = new PgsqlPDOQuerySplitter($this, $reader);
        } else {
            $splitter = new DefaultPDOQuerySplitter($this, $reader, $this->delimiterType);
        }

        try {
            while (null !== ($query = $splitter->nextQuery())) {
                $this->log("SQL: " . $query, Project::MSG_VERBOSE);
                $this->execSQL($query);
            }
        } catch (PDOException $e) {
            throw $e;
        }
    }

    /**
     * Whether the passed-in SQL statement is a SELECT statement.
     * This does a pretty simple match, checking to see if statement starts with
     * 'select' (but not 'select into').
     *
     * @param string $sql
     *
     * @return boolean Whether specified SQL looks like a SELECT query.
     */
    protected function isSelectSql($sql)
    {
        $sql = trim($sql);

        return (stripos($sql, 'select') === 0 && stripos($sql, 'select into ') !== 0);
    }

    /**
     * Exec the sql statement.
     *
     * @param $sql
     *
     * @throws BuildException
     * @throws Exception
     */
    protected function execSQL($sql)
    {
        // Check and ignore empty statements
        if (trim($sql) == "") {
            return;
        }

        try {
            $this->totalSql++;

            $this->statement = $this->conn->query($sql);
            $this->log($this->statement->rowCount() . " rows affected", Project::MSG_VERBOSE);

            // only call processResults() for statements that return actual data (such as 'select')
            if ($this->statement->columnCount() > 0) {
                $this->processResults();
            }

            $this->statement->closeCursor();
            $this->statement = null;

            $this->goodSql++;
        } catch (PDOException $e) {
            $this->log("Failed to execute: " . $sql, Project::MSG_ERR);
            if ($this->onError != "continue") {
                throw new BuildException("Failed to execute SQL", $e);
            }
            $this->log($e->getMessage(), Project::MSG_ERR);
        }
    }

    /**
     * Returns configured PDOResultFormatter objects
     * (which were created from PDOSQLExecFormatterElement objects).
     *
     * @return array PDOResultFormatter[]
     */
    protected function getConfiguredFormatters()
    {
        $formatters = [];
        foreach ($this->formatters as $fe) {
            $formatters[] = $fe->getFormatter();
        }

        return $formatters;
    }

    /**
     * Initialize the formatters.
     */
    protected function initFormatters()
    {
        $formatters = $this->getConfiguredFormatters();
        foreach ($formatters as $formatter) {
            $formatter->initialize();
        }
    }

    /**
     * Run cleanup and close formatters.
     */
    protected function closeFormatters()
    {
        $formatters = $this->getConfiguredFormatters();
        foreach ($formatters as $formatter) {
            $formatter->close();
        }
    }

    /**
     * Passes results from query to any formatters.
     *
     * @throws PDOException
     */
    protected function processResults()
    {
        try {
            $this->log("Processing new result set.", Project::MSG_VERBOSE);

            $formatters = $this->getConfiguredFormatters();

            while ($row = $this->statement->fetch($this->fetchMode)) {
                foreach ($formatters as $formatter) {
                    $formatter->processRow($row);
                }
            }
        } catch (Exception $x) {
            $this->log("Error processing reults: " . $x->getMessage(), Project::MSG_ERR);
            foreach ($formatters as $formatter) {
                $formatter->close();
            }
            throw $x;
        }
    }

    /**
     * Closes current connection
     */
    protected function closeConnection(): void
    {
        if ($this->conn) {
            unset($this->conn);
            $this->conn = null;
        }
    }

    /**
     * PDOSQLExecTask as condition
     *
     * Returns false when the database connection fails, and true otherwise.
     * This method only uses three properties: url (required), userId and
     * password.
     *
     * The database connection is not stored in a variable, this allow to
     * immediately close the connections since there's no reference to it.
     *
     * @author Jawira Portugal <dev@tugal.be>
     *
     * @return bool
     */
    public function evaluate(): bool
    {
        if (empty($this->getUrl())) {
            throw new BuildException('url is required');
        }

        $this->log('Trying to reach ' . $this->getUrl(), Project::MSG_DEBUG);

        try {
            new PDO($this->getUrl(), $this->getUserId(), $this->getPassword());
        } catch (PDOException $ex) {
            $this->log($ex->getMessage(), Project::MSG_VERBOSE);

            return false;
        }

        $this->log('Successful connection to ' . $this->getUrl(), Project::MSG_DEBUG);

        return true;
    }
}
