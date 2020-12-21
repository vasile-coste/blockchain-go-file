
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
	type Farmacy struct {

	}
	
	// history object struct
	type HistoryObject struct {
		TxId 		string 				`json:"TxId"`
		Value 		json.RawMessage 	`json:"Value"`
		IsDelete 	string 				`json:"IsDelete"`
		Timestamp 	string 				`json:"Timestamp"`
	}
		
	// Drug struct
	type Drug struct { 
		AssetType				string `json:"AssetType"`
		Drug_ID				string `json:"Drug_ID"`
		DrugName				string `json:"DrugName"`
		DrugPrice				string `json:"DrugPrice"`
		DrugInfo				string `json:"DrugInfo"`
	}
		
	// Receipe struct
	type Receipe struct { 
		AssetType				string `json:"AssetType"`
		Receipe_ID				string `json:"Receipe_ID"`
		Name				string `json:"Name"`
		Person				string `json:"Person"`
		GeneralInfo				string `json:"GeneralInfo"`
	}
		
	// ===================================================================================
	// Main
	// ===================================================================================
	func main() {
		err := shim.Start(new(Farmacy))
		if err != nil {
			fmt.Printf("Error starting Xebest Trace chaincode: %s", err)
		}
	}

	// Init initializes chaincode
	// ===========================

	func (t *Farmacy) Init(stub shim.ChaincodeStubInterface) peer.Response {
		return shim.Success([]byte("successful initialization"))
	}
	
	// Invoke - Our entry point for Invocations
	// ========================================
	func (t *Farmacy) Invoke(stub shim.ChaincodeStubInterface) peer.Response {
		function, args := stub.GetFunctionAndParameters()
		fmt.Println("\n\n------------------\n")
		fmt.Println("invoke is running -> "+ function)

		// Handle different functions
		
		if function == "queryAsset" { 
			return t.queryAsset(stub, args)
		} else if function == "getAll" { 
			return t.getAll(stub, args)
		} else if function == "getHistoryForRecord" { 
			return t.getHistoryForRecord(stub, args)
		} else if function == "addDrug" { 
			return t.addDrug(stub, args)
		} else if function == "updateDrug" { 
			return t.updateDrug(stub, args)
		} else if function == "getAllDrug" { 
			return t.getAllDrug(stub, args)
		} else if function == "addReceipe" { 
			return t.addReceipe(stub, args)
		} else if function == "updateReceipe" { 
			return t.updateReceipe(stub, args)
		} else if function == "getAllReceipe" { 
			return t.getAllReceipe(stub, args)
		}

		//error
		fmt.Println("invoke did not find func: " + function) 
		return shim.Error("Received unknown function invocation")
	}
		
	// create a record for Drug
	func (t *Farmacy) addDrug(stub shim.ChaincodeStubInterface, args []string) peer.Response {
		// ==== Input sanitation ====
		fmt.Println("- start addDrug")

		// check if all the args are send
		if len(args) != 3 {
			fmt.Println("Incorrect number of arguments, Required 3 arguments")
			return shim.Error("Incorrect number of arguments, Required 3 arguments")
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
		var obj = Drug{}

		obj.Drug_ID = ComputeHashKey(args[0]+(txTimeAsPtr.String()))
		obj.DrugName = args[0]
		obj.DrugPrice = args[1]
		obj.DrugInfo = args[2]
		obj.AssetType = "Drug"

		// convert to bytes
		assetAsBytes, errMarshal := json.Marshal(obj)
		
		// show error if failed
		if errMarshal != nil {
			return shim.Error(fmt.Sprintf("Marshal Error: %s", errMarshal))
		}

		// save data in blockchain
		errPut := stub.PutState(obj.Drug_ID, assetAsBytes)

		if errPut != nil {
			return shim.Error(fmt.Sprintf("Failed to save file data : %s", obj.Drug_ID))
		}

		fmt.Println("Success in saving file data %v", obj)


		return shim.Success(assetAsBytes)
	}
		
	// update a record for Drug
	func (t *Farmacy) updateDrug(stub shim.ChaincodeStubInterface, args []string) peer.Response {
		// ==== Input sanitation ====
		fmt.Println("- start updateDrug")

		// check if all the args are send
		if len(args) != 4 {
			return shim.Error("Incorrect number of arguments, Required 4 arguments")
		}

		// check if the args are empty
		for i := 0; i < len(args); i++ {
			if len(args[i]) <= 0 {
				return shim.Error("argument "+ string(i+1)  + " must be a non-empty string")
			}
		}

		getAssetAsBytes, errT := stub.GetState(args[0])

		if errT != nil {
			return shim.Error(fmt.Sprintf("Error : Cannot find Drug %s" , errT))
		}

		if getAssetAsBytes == nil {
			return shim.Error(fmt.Sprintf("Cannot find asset Drug with ID %s" , args[0]))
		}

		var obj = Drug{}

		json.Unmarshal(getAssetAsBytes, &obj)
		obj.DrugName = args[1]
		obj.DrugPrice = args[2]
		obj.DrugInfo = args[3]
		comAssetAsBytes, errMarshal := json.Marshal(obj)

		if errMarshal != nil {
			return shim.Error(fmt.Sprintf("Marshal Error: %s", errMarshal))
		}

		errPut := stub.PutState(obj.Drug_ID, comAssetAsBytes)

		if errPut != nil {
			return shim.Error(fmt.Sprintf("Failed to update Drug with ID %s", args[0]))
		}

		fmt.Println("Drug asset with ID %s was updated \n %v", args[0], obj)

		return shim.Success(comAssetAsBytes)
	}
			
	// get all records for asset Drug
	func (t *Farmacy) getAllDrug(stub shim.ChaincodeStubInterface, args []string) peer.Response {
		// ==== Input sanitation ====
		fmt.Println("- start getAllDrug")

		AssetType := "Drug"

		queryString := fmt.Sprintf("SELECT valueJson FROM <STATE> WHERE json_extract(valueJson, '$.AssetType') = '%s'", AssetType)
		
		queryResults, err := getQueryResultForQueryString(stub, queryString)
		
		if err != nil {
			return shim.Error(err.Error())
		}
		
		return shim.Success(queryResults)
	}
		
	// create a record for Receipe
	func (t *Farmacy) addReceipe(stub shim.ChaincodeStubInterface, args []string) peer.Response {
		// ==== Input sanitation ====
		fmt.Println("- start addReceipe")

		// check if all the args are send
		if len(args) != 3 {
			fmt.Println("Incorrect number of arguments, Required 3 arguments")
			return shim.Error("Incorrect number of arguments, Required 3 arguments")
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
		var obj = Receipe{}

		obj.Receipe_ID = ComputeHashKey(args[0]+(txTimeAsPtr.String()))
		obj.Name = args[0]
		obj.Person = args[1]
		obj.GeneralInfo = args[2]
		obj.AssetType = "Receipe"

		// convert to bytes
		assetAsBytes, errMarshal := json.Marshal(obj)
		
		// show error if failed
		if errMarshal != nil {
			return shim.Error(fmt.Sprintf("Marshal Error: %s", errMarshal))
		}

		// save data in blockchain
		errPut := stub.PutState(obj.Receipe_ID, assetAsBytes)

		if errPut != nil {
			return shim.Error(fmt.Sprintf("Failed to save file data : %s", obj.Receipe_ID))
		}

		fmt.Println("Success in saving file data %v", obj)


		return shim.Success(assetAsBytes)
	}
		
	// update a record for Receipe
	func (t *Farmacy) updateReceipe(stub shim.ChaincodeStubInterface, args []string) peer.Response {
		// ==== Input sanitation ====
		fmt.Println("- start updateReceipe")

		// check if all the args are send
		if len(args) != 4 {
			return shim.Error("Incorrect number of arguments, Required 4 arguments")
		}

		// check if the args are empty
		for i := 0; i < len(args); i++ {
			if len(args[i]) <= 0 {
				return shim.Error("argument "+ string(i+1)  + " must be a non-empty string")
			}
		}

		getAssetAsBytes, errT := stub.GetState(args[0])

		if errT != nil {
			return shim.Error(fmt.Sprintf("Error : Cannot find Receipe %s" , errT))
		}

		if getAssetAsBytes == nil {
			return shim.Error(fmt.Sprintf("Cannot find asset Receipe with ID %s" , args[0]))
		}

		var obj = Receipe{}

		json.Unmarshal(getAssetAsBytes, &obj)
		obj.Name = args[1]
		obj.Person = args[2]
		obj.GeneralInfo = args[3]
		comAssetAsBytes, errMarshal := json.Marshal(obj)

		if errMarshal != nil {
			return shim.Error(fmt.Sprintf("Marshal Error: %s", errMarshal))
		}

		errPut := stub.PutState(obj.Receipe_ID, comAssetAsBytes)

		if errPut != nil {
			return shim.Error(fmt.Sprintf("Failed to update Receipe with ID %s", args[0]))
		}

		fmt.Println("Receipe asset with ID %s was updated \n %v", args[0], obj)

		return shim.Success(comAssetAsBytes)
	}
			
	// get all records for asset Receipe
	func (t *Farmacy) getAllReceipe(stub shim.ChaincodeStubInterface, args []string) peer.Response {
		// ==== Input sanitation ====
		fmt.Println("- start getAllReceipe")

		AssetType := "Receipe"

		queryString := fmt.Sprintf("SELECT valueJson FROM <STATE> WHERE json_extract(valueJson, '$.AssetType') = '%s'", AssetType)
		
		queryResults, err := getQueryResultForQueryString(stub, queryString)
		
		if err != nil {
			return shim.Error(err.Error())
		}
		
		return shim.Success(queryResults)
	}
		
	// get data using a record id 
	func (t *Farmacy) queryAsset(APIstub shim.ChaincodeStubInterface, args []string) peer.Response {
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
	func (t *Farmacy) getAll(stub shim.ChaincodeStubInterface, args []string) peer.Response {
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
	func (t *Farmacy) getHistoryForRecord(stub shim.ChaincodeStubInterface, args []string) peer.Response {
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
	func (t *Farmacy) getHistory(stub shim.ChaincodeStubInterface, recordKey string) []byte {

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
	func (t *Farmacy) GetTxTimestampChannel(APIstub shim.ChaincodeStubInterface) (time.Time, error) {
		txTimeAsPtr, err := APIstub.GetTxTimestamp()
		if err != nil {
			fmt.Printf("Returning error in TimeStamp \n")
		}
		fmt.Printf("\t returned value from APIstub: %v\n", txTimeAsPtr)
		timeInt := time.Unix(txTimeAsPtr.Seconds, int64(txTimeAsPtr.Nanos))

		return timeInt, nil
	}
		