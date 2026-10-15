<?php
namespace Magezon\Core\Model;

class RequestProcessor
{
    protected $resource;
    protected $request;

    /**
     * @var \Magezon\Core\Model\Request\MultipartFormDataParser
     */
    protected $multipartParser;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Framework\App\RequestInterface $request,
        \Magezon\Core\Model\Request\MultipartFormDataParser $multipartParser
    ) {
        $this->resource = $resource;
        $this->request = $request;
        $this->multipartParser = $multipartParser;
    }

    public function getSelectCountSql(\Magento\Framework\DB\Select $select, string $mainField)
    {
        $countSelect = clone $select;
        $countSelect->reset(\Magento\Framework\DB\Select::ORDER);
        $countSelect->reset(\Magento\Framework\DB\Select::LIMIT_COUNT);
        $countSelect->reset(\Magento\Framework\DB\Select::LIMIT_OFFSET);
        $countSelect->reset(\Magento\Framework\DB\Select::COLUMNS);

        $part = $select->getPart(\Magento\Framework\DB\Select::GROUP);
        if (!is_array($part) || !count($part)) {
            $countSelect->columns(new \Zend_Db_Expr('COUNT(*)'));
            return $countSelect;
        }

        $countSelect->reset(\Magento\Framework\DB\Select::GROUP);
        $group = $select->getPart(\Magento\Framework\DB\Select::GROUP);
        $countSelect->columns(new \Zend_Db_Expr(("COUNT(DISTINCT ".implode(", ", $group).")")));
        return $countSelect;
    }

    /**
     * The request body, whatever PHP did or did not populate from it.
     *
     * Reads `$_POST` first and hand-parses multipart only when it is empty.
     * That ordering is what makes one accessor serve every transport the editor
     * uses, and each branch has a reason:
     *
     * - POST urlencoded / POST multipart — PHP fills `$_POST` itself.
     * - JSON, any method — `Magezon\Editor`'s `Plugin\App\AbstractAction`
     *   decodes it into `setPostValue()` before the controller is reached.
     * - PUT multipart — the one case nothing fills, because PHP populates
     *   `$_POST` from a multipart body on POST only. Hence the fallback.
     *
     * Before this, the method went straight to the multipart parser, so a JSON
     * body read as no fields at all: the 14 controllers on this accessor
     * forwarded to their update action with an empty payload and changed
     * nothing, silently. That became reachable when `apiFetch`'s
     * `formDataMiddleware` turned opt-in (ReactAPP `eb86e3af80`) and every write
     * without a file became JSON.
     *
     * @return array
     */
    public function getPost()
    {
        $post = (array) $this->request->getPostValue();

        if (!empty($post)) {
            return $post;
        }

        return $this->multipartParser->parse();
    }

    public function processListRequestSelect(array $request, \Magento\Framework\DB\Select $select, string $mainField, string $titleField = '')
    {
        $order = isset($request['order']) ? $request['order'] : null;
        $orderby = isset($request['orderby']) ? $request['orderby'] : null;

        $search = isset($request['search']) ? trim($request['search']) : '';
        if ($search && !empty($titleField)) {
            $searchRaw = str_replace(['_', '%', '\\'], ['\\_', '\\%', '\\\\'], $search);
            $select->where("$titleField LIKE ?", "%$searchRaw%");
        }

        if ($orderby) {
            $select->orderBy("$orderby $order");
        }

        $perPage = (int) ($request['per_page'] ?? 50) ?: 50;
        $page = (int) ($request['page'] ?? 1) ?: 1;
        $select->limitPage(($page - 1) * $perPage, $perPage);

        $connection = $this->resource->getConnection();

        // Get total records count
        $countSql = $this->getSelectCountSql($select, $mainField)->limit(1)->assemble();
        $totalRecords = $connection->fetchOne($countSql);

        $items = $connection->fetchAll($select->assemble());

        $totalPages = ceil($totalRecords / $perPage);

        return [
            'items' => $items,
            'totalPages' => $totalPages,
            'totalRecords' => $totalRecords
        ];

    }

    /**
     * Apply search / sort / pagination to a collection and return the picker payload.
     *
     * Goes through the collection API rather than getSelect(), so EAV collections
     * (product, category) resolve $titleField as an attribute: addFieldToFilter and
     * setOrder are overridden to addAttributeToFilter/addAttributeToSort, and
     * getSize() counts against the joined select. Touching getSelect() directly
     * would emit `WHERE name LIKE ...` against catalog_product_entity, which has
     * no such column.
     *
     * @param array                                          $request
     * @param \Magento\Framework\Data\Collection\AbstractDb   $collection
     * @param string                                         $mainField
     * @param string                                         $titleField
     * @return array
     */
    public function processListRequestSelect2(array $request, \Magento\Framework\Data\Collection\AbstractDb $collection, string $mainField, string $titleField = '')
    {
        $search = isset($request['search']) ? trim($request['search']) : '';
        if ($search && !empty($titleField)) {
            $searchRaw = str_replace(['_', '%', '\\'], ['\\_', '\\%', '\\\\'], $search);
            $collection->addFieldToFilter($titleField, ['like' => "%$searchRaw%"]);
        }

        $orderby = isset($request['orderby']) ? (string) $request['orderby'] : '';
        $sortable = array_filter([$mainField, $titleField]);
        if ($orderby && in_array($orderby, $sortable, true)) {
            $order = strtoupper((string) ($request['order'] ?? '')) === 'DESC' ? 'DESC' : 'ASC';
            $collection->setOrder($orderby, $order);
        }

        $perPage = (int) ($request['per_page'] ?? 50) ?: 50;
        $page = (int) ($request['page'] ?? 1) ?: 1;
        $collection->setPageSize($perPage)->setCurPage($page);

        $totalRecords = (int) $collection->getSize();

        $items = [];

        foreach ($collection as $item) {
            $items[] = [
                'id' => (int) $item->getId(),
                'name' => $item->getData($titleField)
            ];
        }

        $totalPages = (int) ceil($totalRecords / $perPage);

        return [
            'items' => $items,
            'totalPages' => $totalPages,
            'totalRecords' => $totalRecords
        ];

    }
}