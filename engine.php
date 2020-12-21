<?php

//$_POST = json_decode(file_get_contents('files/MedicalApp.js'), true);
//print_r($_POST);

(new generateGoFile($_POST))->run();

class generateGoFile {
	
	/**
	* @array
	*/
	private $blockchainData;
	
	/**
	* @array
	*/
	private $structures;
	
	/**
	* @string
	*/
	private $blockchainName;
	
	/**
	* @param array $structures
	*/
	public function __construct(array $blockchainData){
		$this->blockchainData = $blockchainData;
		$this->blockchainName = $this->sanitazeString($blockchainData['blockchainName']);
		$this->structures = $this->sanitazeStructure($blockchainData['structures']);
	}
	
	public function run()
	{
		$dir = 'files/';
		
		$fileName = $this->blockchainName . '.go';
		$fileNameJson = $this->blockchainName . '.js';
		
		if(file_exists($dir . $fileName)){
			unlink($dir . $fileName);
		}
		
		if(file_exists($dir . $fileNameJson)){
			unlink($dir . $fileNameJson);
		}
		
		$data = $this->generate();
		
		// create go file
		$myfile = fopen($dir . $fileName, "w") or die(json_encode(["status" => "error", "msg" => "Unable to open file!"]));
		fwrite($myfile, $data);
		fclose($myfile);
		
		// create js with json data 
		$myfileJson = fopen($dir . $fileNameJson, "w") or die(json_encode(["status" => "error", "msg" => "Unable to open file!"]));
		fwrite($myfileJson, json_encode($this->blockchainData));
		fclose($myfileJson);
		
		echo json_encode(["status" => "success", "msg" => $dir . $fileName]);
	}
	
	/**
	* @return string
	*/
	private function generate():string
	{
		$structures = '';
		$methods = '';
		$codeStart = $this->codeStart();
		$invoke = $this->getMainMethodsAndInvoke();
		$helpers= $this->codeHistoryAndHelpers();
		
		foreach($this->structures as $structure){
			$structures .= $this->codeCreateStruct($structure);
			$methods .= $this->codeCreateMethod($structure);
		}
		
		return $codeStart . $structures . $invoke . $methods . $helpers;
	}
	
	/**
	* @return string
	*/
	private function getMainMethodsAndInvoke():string
	{
		$invoke = '
		if function == "queryAsset" { 
			return t.queryAsset(stub, args)
		} else if function == "getAll" { 
			return t.getAll(stub, args)
		} else if function == "getHistoryForRecord" { 
			return t.getHistoryForRecord(stub, args)
		}';
		foreach($this->structures as $structure){
			$invoke .= ' else if function == "add' . $structure['structureName'] . '" { 
			return t.add' . $structure['structureName'] . '(stub, args)
		}';
			$createUpdateMethod = [];
			foreach($structure['properties'] as $prop){
				// if at least one property has the update value set to 1 then we create an update method
				if($prop['update'] == 1){
					$createUpdateMethod[] = $prop['prop'];
				}
			}
			if(count($createUpdateMethod) > 0){
				$invoke .= ' else if function == "update' . $structure['structureName'] . '" { 
			return t.update' . $structure['structureName'] . '(stub, args)
		}';
			}
			$invoke .= ' else if function == "getAll' . $structure['structureName'] . '" { 
			return t.getAll' . $structure['structureName'] . '(stub, args)
		}';
		}
		
		return '
	// ===================================================================================
	// Main
	// ===================================================================================
	func main() {
		err := shim.Start(new(' . $this->blockchainName . '))
		if err != nil {
			fmt.Printf("Error starting Xebest Trace chaincode: %s", err)
		}
	}

	// Init initializes chaincode
	// ===========================

	func (t *' . $this->blockchainName . ') Init(stub shim.ChaincodeStubInterface) peer.Response {
		return shim.Success([]byte("successful initialization"))
	}
	
	// Invoke - Our entry point for Invocations
	// ========================================
	func (t *' . $this->blockchainName . ') Invoke(stub shim.ChaincodeStubInterface) peer.Response {
		function, args := stub.GetFunctionAndParameters()
		fmt.Println("\n\n------------------\n")
		fmt.Println("invoke is running -> "+ function)

		// Handle different functions
		' . $invoke . '

		//error
		fmt.Println("invoke did not find func: " + function) 
		return shim.Error("Received unknown function invocation")
	}
		';
	}
	
	/**
	* @return string
	*/
	private function codeCreateMethod($structure):string
	{
		// add method
		$methods = '
	// create a record for ' . $structure['structureName'] . '
	func (t *' . $this->blockchainName . ') add' . $structure['structureName'] . '(stub shim.ChaincodeStubInterface, args []string) peer.Response {
		// ==== Input sanitation ====
		fmt.Println("- start add' . $structure['structureName'] . '")

		// check if all the args are send
		if len(args) != ' . count($structure['properties']) . ' {
			fmt.Println("Incorrect number of arguments, Required ' . count($structure['properties']) . ' arguments")
			return shim.Error("Incorrect number of arguments, Required ' . count($structure['properties']) . ' arguments")
		}

		// check if the args are empty
		for i := 0; i < len(args); i++ {
			if len(args[i]) <= 0 {
				fmt.Println("argument "+ string(i+1)  + " must be a non-empty string")
				return shim.Error("argument "+ string(i+1)  + " must be a non-empty string")
			}
		}

		// get timestamp
		txTimeAsPtr, errTx := t.GetTxTimestampChannel(stub)
		if errTx != nil {
			return shim.Error("Returning time error")
		}

		// create the object
		var obj = ' . $structure['structureName'] . '{}

		obj.' . $structure['structureName'] . '_ID = ComputeHashKey(args[0]+(txTimeAsPtr.String()))';
				
		$x = 0;
		$createUpdateMethod = [];
		foreach($structure['properties'] as $prop){
			$methods .= '
		obj.' . $prop['prop'] . ' = args[' . $x . ']';
			// if at least one property has the update value set to 1 then we create an update method
			if($prop['update'] == 1){
				$createUpdateMethod[] = $prop['prop'];
			}
			$x++;
		}
				
		$methods .= '
		obj.AssetType = "' . $structure['structureName'] . '"

		// convert to bytes
		assetAsBytes, errMarshal := json.Marshal(obj)
		
		// show error if failed
		if errMarshal != nil {
			return shim.Error(fmt.Sprintf("Marshal Error: %s", errMarshal))
		}

		// save data in blockchain
		errPut := stub.PutState(obj.' . $structure['structureName'] . '_ID, assetAsBytes)

		if errPut != nil {
			return shim.Error(fmt.Sprintf("Failed to save file data : %s", obj.' . $structure['structureName'] . '_ID))
		}

		fmt.Println("Success in saving file data %v", obj)


		return shim.Success(assetAsBytes)
	}
		';
		
		// update method
		if(count($createUpdateMethod) > 0) {
			$methods .= '
	// update a record for ' . $structure['structureName'] . '
	func (t *' . $this->blockchainName . ') update' . $structure['structureName'] . '(stub shim.ChaincodeStubInterface, args []string) peer.Response {
		// ==== Input sanitation ====
		fmt.Println("- start update' . $structure['structureName'] . '")

		// check if all the args are send
		if len(args) != ' . (count($createUpdateMethod) + 1) . ' {
			return shim.Error("Incorrect number of arguments, Required ' . (count($createUpdateMethod) + 1) . ' arguments")
		}

		// check if the args are empty
		for i := 0; i < len(args); i++ {
			if len(args[i]) <= 0 {
				return shim.Error("argument "+ string(i+1)  + " must be a non-empty string")
			}
		}

		getAssetAsBytes, errT := stub.GetState(args[0])

		if errT != nil {
			return shim.Error(fmt.Sprintf("Error : Cannot find ' . $structure['structureName'] . ' %s" , errT))
		}

		if getAssetAsBytes == nil {
			return shim.Error(fmt.Sprintf("Cannot find asset ' . $structure['structureName'] . ' with ID %s" , args[0]))
		}

		var obj = ' . $structure['structureName'] . '{}

		json.Unmarshal(getAssetAsBytes, &obj)';
			$x = 1;
			foreach($createUpdateMethod as $propUp){
			$methods .= '
		obj.' . $propUp . ' = args[' . $x . ']';
				$x++;
			}

			$methods .=	'
		comAssetAsBytes, errMarshal := json.Marshal(obj)

		if errMarshal != nil {
			return shim.Error(fmt.Sprintf("Marshal Error: %s", errMarshal))
		}

		errPut := stub.PutState(obj.' . $structure['structureName'] . '_ID, comAssetAsBytes)

		if errPut != nil {
			return shim.Error(fmt.Sprintf("Failed to update ' . $structure['structureName'] . ' with ID %s", args[0]))
		}

		fmt.Println("' . $structure['structureName'] . ' asset with ID %s was updated \n %v", args[0], obj)

		return shim.Success(comAssetAsBytes)
	}
			';
		}
		
		// get all method
		$methods .= '
	// get all records for asset ' . $structure['structureName'] . '
	func (t *' . $this->blockchainName . ') getAll' . $structure['structureName'] . '(stub shim.ChaincodeStubInterface, args []string) peer.Response {
		// ==== Input sanitation ====
		fmt.Println("- start getAll' . $structure['structureName'] . '")

		AssetType := "' . $structure['structureName'] . '"

		queryString := fmt.Sprintf("SELECT valueJson FROM <STATE> WHERE json_extract(valueJson, \'$.AssetType\') = \'%s\'", AssetType)
		
		queryResults, err := getQueryResultForQueryString(stub, queryString)
		
		if err != nil {
			return shim.Error(err.Error())
		}
		
		return shim.Success(queryResults)
	}
		';
		
		
		
		return $methods;
	}
	
	/**
	* @return string
	*/
	private function codeCreateStruct($structure):string
	{
		// add asset type
		$properties = '
		AssetType				string `json:"AssetType"`
		'.$structure['structureName'] . '_ID				string `json:"' . $structure['structureName'] . '_ID"`';
		
		// add the rest of the properties
		foreach($structure['properties'] as $prop){
			$properties .= '
		'.$prop['prop'] . '				string `json:"' . $prop['prop'] . '"`';
		}
		
		return '
	// ' . $structure['structureName'] . ' struct
	type ' . $structure['structureName'] . ' struct { ' . $properties . '
	}
		';
	}
	
	/**
	* @return string
	*/
	private function codeStart():string
	{
		return '
	package main

	import (
		"bytes"
		"strconv"
		"errors"
		"crypto/sha256"
		"encoding/hex"
		"encoding/json"
		"fmt"
		"github.com/hyperledger/fabric/core/chaincode/shim"
		"github.com/hyperledger/fabric/protos/peer"
		"time"
	)
	
	//  Chaincode implementation
	type ' . $this->blockchainName . ' struct {

	}
	
	// history object struct
	type HistoryObject struct {
		TxId 		string 				`json:"TxId"`
		Value 		json.RawMessage 	`json:"Value"`
		IsDelete 	string 				`json:"IsDelete"`
		Timestamp 	string 				`json:"Timestamp"`
	}
		';
	}
	
	/**
	* @return string
	*/
	private function codeHistoryAndHelpers():string
	{
		return '
	// get data using a record id 
	func (t *' . $this->blockchainName . ') queryAsset(APIstub shim.ChaincodeStubInterface, args []string) peer.Response {
		if len(args) != 1 {
			return shim.Error("Incorrect number of arguments, Required 1")
		}

		fmt.Println(fmt.Sprintf("- start queryAsset: %s\n", args))

		AssetAsBytes, _ := APIstub.GetState(args[0])

		if AssetAsBytes == nil {
			return shim.Error("Could not locate Asset")

		}
		
		return shim.Success(AssetAsBytes)
	}

	// get all records of any kind
	func (t *' . $this->blockchainName . ') getAll(stub shim.ChaincodeStubInterface, args []string) peer.Response {
		// ==== Input sanitation ====
		fmt.Println("- start getAll")

		// query 
		queryString := "SELECT blockNo, key, valueJson FROM <STATE> WHERE 1"
		
		queryResults, err := getQueryResultForQueryString(stub, queryString)
		
		if err != nil {
			return shim.Error(err.Error())
		}
		
		return shim.Success(queryResults)
	}

	// ===================================================================================
	// Retrieving all the changes to a record
	// ===================================================================================
	func (t *' . $this->blockchainName . ') getHistoryForRecord(stub shim.ChaincodeStubInterface, args []string) peer.Response {
		// ==== Input sanitation ====
		fmt.Println(fmt.Sprintf("- start getHistoryForRecord: %s\n", args))

		var parentHistObj []HistoryObject

		transactionBytes := t.getHistory(stub, args[0])

		var data []HistoryObject
		json.Unmarshal(transactionBytes, &data)

		fmt.Println(fmt.Sprintf("Transaction History: %v \n", data))
		parentHistObj = append(parentHistObj,data...)

		parentHistArrBytes, _ := json.Marshal(parentHistObj)

		return shim.Success(parentHistArrBytes)
	}

	// helper to get all changes for a record
	func (t *' . $this->blockchainName . ') getHistory(stub shim.ChaincodeStubInterface, recordKey string) []byte {

		fmt.Printf("- start getHistory: %s\n", recordKey)

		resultsIterator, err := stub.GetHistoryForKey(recordKey)
		if err != nil {
			errors.New(err.Error())
		//	return shim.Error(err.Error())
		}
		defer resultsIterator.Close()

		// buffer is a JSON array containing historic values for the key/value pair
		var buffer bytes.Buffer
		buffer.WriteString("[")

		bArrayMemberAlreadyWritten := false
		for resultsIterator.HasNext() {
			response, err := resultsIterator.Next()
			if err != nil {
				errors.New(err.Error())
				//return shim.Error(err.Error())
			}
			// Add a comma before array members, suppress it for the first array member
			if bArrayMemberAlreadyWritten == true {
				buffer.WriteString(",")
			}
			buffer.WriteString("{\"TxId\":")
			buffer.WriteString("\"")
			buffer.WriteString(response.TxId)
			buffer.WriteString("\"")

			buffer.WriteString(", \"Value\":")
			// if it was a delete operation on given key, then we need to set the
			//corresponding value null. Else, we will write the response.Value
			//as-is (as the Value itself a JSON vehiclePart)
			if response.IsDelete {
				buffer.WriteString("null")
			} else {
				buffer.WriteString(string(response.Value))
			}

			buffer.WriteString(", \"Timestamp\":")
			buffer.WriteString("\"")
			buffer.WriteString(time.Unix(response.Timestamp.Seconds, int64(response.Timestamp.Nanos)).String())
			buffer.WriteString("\"")

			buffer.WriteString(", \"IsDelete\":")
			buffer.WriteString("\"")
			buffer.WriteString(strconv.FormatBool(response.IsDelete))
			buffer.WriteString("\"")

			buffer.WriteString("}")
			bArrayMemberAlreadyWritten = true
		}
		buffer.WriteString("]")

		fmt.Printf("- getHistoryForRecord returning:\n%s\n", buffer.String())

		return buffer.Bytes()
	}

	// ===================================================================================
	// Helpers
	// ===================================================================================

	// helper for getting all rows from a query
	func getQueryResultForQueryString(stub shim.ChaincodeStubInterface, queryString string) ([]byte, error) {	

		fmt.Printf("- getQueryResultForQueryString queryString:\n%s\n", queryString)

		resultsIterator, err := stub.GetQueryResult(queryString)
		if err != nil {
			return nil, err
		}
		defer resultsIterator.Close()

		// buffer is a JSON array containing QueryRecords
		var buffer bytes.Buffer
		buffer.WriteString("[")

		bArrayMemberAlreadyWritten := false
		for resultsIterator.HasNext() {
			queryResponse, err := resultsIterator.Next()
			if err != nil {
				return nil, err
			}
			// Add a comma before array members, suppress it for the first array member
			if bArrayMemberAlreadyWritten == true {
				buffer.WriteString(",")
			}

			buffer.WriteString(string(queryResponse.Value))
			bArrayMemberAlreadyWritten = true
		}
		buffer.WriteString("]")

		fmt.Printf("- getQueryResultForQueryString queryResult:\n%s\n", buffer.String())

		return buffer.Bytes(), nil
	}

	// generate a random unique key hash
	func ComputeHashKey(propertyName string) string {
		h := sha256.New()
		h.Write([]byte(propertyName))
		nameInBytes := h.Sum([]byte(""))
		nameInString := hex.EncodeToString(nameInBytes)
		return  nameInString[:62]
	}

	// get timestamp in seconds
	func (t *' . $this->blockchainName . ') GetTxTimestampChannel(APIstub shim.ChaincodeStubInterface) (time.Time, error) {
		txTimeAsPtr, err := APIstub.GetTxTimestamp()
		if err != nil {
			fmt.Printf("Returning error in TimeStamp \n")
		}
		fmt.Printf("\t returned value from APIstub: %v\n", txTimeAsPtr)
		timeInt := time.Unix(txTimeAsPtr.Seconds, int64(txTimeAsPtr.Nanos))

		return timeInt, nil
	}
		';
	}
	
	/**
	* @param array $data
	* @return array
	*/
	private function sanitazeStructure(array $data):array
	{
		$newStructure = [];
		foreach($data as $structure){
			$properties = [];
			foreach($structure['properties'] as $prop){
				$prop['prop'] = $this->sanitazeString($prop['prop']);
				$properties[] = $prop;
			}
			
			$newStructure[] = [
				'structureName' => $this->sanitazeString($structure['structureName']),
				'properties' => $properties
			];
		}
		
		return $newStructure;
	}
	
	/**
	* @param string $string
	* @return string
	*/
	private function sanitazeString(string $string):string
	{
		return trim(ucfirst(str_replace([" "], "_", $string)));
	}
}